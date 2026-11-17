<?php

namespace Modules\Accounting\Actions;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Models\OpeningBalance;
use Modules\Accounting\Models\OpeningBalanceLine;
use Modules\Accounting\Services\PostingService;
use Modules\Accounting\Workflow\OpeningBalanceProcess;
use Modules\Core\Support\Numbering;
use Modules\Payments\Models\PaymentRequest;
use Modules\Procurement\Actions\ManageSuppliers;
use Modules\Procurement\Models\Supplier;
use Modules\Receivables\Actions\ManageCustomers;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Go-live balances. Account balances post one opening journal; creditors and debtors are the detail behind those
 * totals and become open bills and invoices without touching the ledger again; breakdowns are supporting detail only.
 */
class ManageOpeningBalances
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting) {}

    public function firstYear(): FiscalYear
    {
        $year = FiscalYear::orderBy('start_date')->first();
        if (! $year) {
            throw ValidationException::withMessages(['date' => 'Create the first fiscal year in Accounting → Fiscal years before loading opening balances.']);
        }
        if (FiscalYear::where('status', '!=', 'open')->exists()) {
            throw ValidationException::withMessages(['date' => 'A fiscal year is already closed; opening balances now come from the year-end close. Correct balances with an adjustment journal.']);
        }

        return $year;
    }

    private function checkDate(string $date): void
    {
        $year = $this->firstYear();
        if (Carbon::parse($date)->lt($year->start_date) || Carbon::parse($date)->gt($year->end_date)) {
            throw ValidationException::withMessages(['date' => "Opening balances are dated within the first fiscal year ({$year->start_date->format('j M Y')} to {$year->end_date->format('j M Y')}), usually its first day."]);
        }
    }

    public function create(array $data, User $by): OpeningBalance
    {
        $this->checkDate($data['date']);

        return OpeningBalance::create(['number' => Numbering::next(OpeningBalance::class, 'OPB', $data['date']), 'kind' => $data['kind'], 'date' => $data['date'],
            'notes' => $data['notes'] ?? null, 'status' => 'draft', 'created_by' => $by->id]);
    }

    public function update(OpeningBalance $batch, array $data): OpeningBalance
    {
        $this->editable($batch);
        $this->checkDate($data['date']);
        $batch->update(['date' => $data['date'], 'notes' => $data['notes'] ?? null]);

        return $batch->refresh();
    }

    private function editable(OpeningBalance $batch): void
    {
        if (! $batch->editable()) {
            throw ValidationException::withMessages(['batch' => 'Approved or pending balances cannot be changed. After approval, correct them with an adjustment journal.']);
        }
    }

    /**
     * Check each row on its own; good rows are kept, bad rows come back with the reason.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{added: int, results: list<array{row: int, reference: string|null, ok: bool, message: string}>}
     */
    public function addRows(OpeningBalance $batch, array $rows, User $by): array
    {
        $this->editable($batch);
        $results = [];
        $added = 0;
        foreach (array_values($rows) as $i => $row) {
            $ref = trim((string) ($row['reference'] ?? '')) ?: null;
            try {
                DB::transaction(function () use ($batch, $row, $ref, $by) {
                    OpeningBalanceLine::create($this->normalise($batch, $row, $ref, $by));
                });
                $added++;
                $results[] = ['row' => $i + 1, 'reference' => $ref, 'ok' => true, 'message' => 'Added'];
            } catch (ValidationException $e) {
                $results[] = ['row' => $i + 1, 'reference' => $ref, 'ok' => false, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['added' => $added, 'results' => $results];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['row' => $message]);
    }

    private function normalise(OpeningBalance $batch, array $row, ?string $ref, User $by): array
    {
        if (! $ref) {
            $this->fail('Every row needs a reference.');
        }
        if (OpeningBalanceLine::where('kind', $batch->kind)->where('reference', $ref)->exists()) {
            $this->fail("Reference {$ref} is already loaded.");
        }
        $account = $this->account($batch, $row);
        $out = ['opening_balance_id' => $batch->id, 'kind' => $batch->kind, 'reference' => $ref, 'account_id' => $account->id, 'description' => $this->text($row['description'] ?? null, 255),
            'department_id' => $this->department($row), 'fund_source_id' => $this->fund($row), 'task_activity_id' => $row['task_activity_id'] ?? null, 'debit' => 0, 'credit' => 0];
        if ($batch->kind === 'accounts') {
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);
            if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
                $this->fail('Enter either a debit or a credit amount, not both.');
            }

            return array_merge($out, ['debit' => $debit, 'credit' => $credit]);
        }
        $amount = round((float) ($row['amount'] ?? 0), 2);
        if ($amount <= 0) {
            $this->fail('The amount must be more than zero.');
        }
        $dates = ['document_date' => $this->date($row['document_date'] ?? null) ?? $batch->date->toDateString(), 'due_date' => $this->date($row['due_date'] ?? null)];
        $dates['due_date'] ??= $dates['document_date'];

        return match ($batch->kind) {
            'creditors' => array_merge($out, $dates, ['credit' => $amount, 'supplier_id' => $this->supplier($row, $by)->id, 'party' => null]),
            'debtors' => array_merge($out, $dates, ['debit' => $amount, 'customer_id' => $this->customer($row)->id, 'party' => null]),
            default => array_merge($out, in_array($account->type, Account::DEBIT_NORMAL, true) ? ['debit' => $amount] : ['credit' => $amount], ['party' => $this->text($row['party'] ?? null, 200)]),
        };
    }

    private function account(OpeningBalance $batch, array $row): Account
    {
        $control = ReceivableSetting::current()->receivable_account_id;
        $account = ! empty($row['account_id']) ? Account::find($row['account_id'])
            : (! empty($row['account_code']) ? Account::where('code', trim((string) $row['account_code']))->first() : ($batch->kind === 'debtors' && $control ? Account::find($control) : null));
        if (! $account) {
            $this->fail('Unknown account'.(! empty($row['account_code']) ? " {$row['account_code']}." : '.'));
        }
        if (! $account->is_active || ! $account->is_postable) {
            $this->fail("{$account->label()} is a heading or inactive; choose an account that takes postings.");
        }
        if ($batch->kind === 'creditors' && $account->type !== 'liability') {
            $this->fail("{$account->label()} is not a liability account.");
        }
        if ($batch->kind === 'debtors' && $account->id !== $control) {
            $this->fail('Debtors are loaded against the receivables control account set in Billing & Receipts settings.');
        }

        return $account;
    }

    private function department(array $row): ?int
    {
        if (! empty($row['department_id'])) {
            return Department::find($row['department_id'])?->id ?? $this->fail('Unknown department.');
        }
        $name = trim((string) ($row['department'] ?? ''));

        return $name === '' ? null : (Department::where(fn ($q) => $q->where('name', $name)->orWhere('short_name', $name))->value('id') ?? $this->fail("Unknown department {$name}."));
    }

    private function fund(array $row): ?int
    {
        if (! empty($row['fund_source_id'])) {
            return FundSource::find($row['fund_source_id'])?->id ?? $this->fail('Unknown fund source.');
        }
        $code = trim((string) ($row['fund_source'] ?? ''));

        return $code === '' ? null : (FundSource::where('code', $code)->value('id') ?? $this->fail("Unknown fund source {$code}."));
    }

    private function supplier(array $row, User $by): Supplier
    {
        if (! empty($row['supplier_id'])) {
            return Supplier::find($row['supplier_id']) ?? $this->fail('Unknown supplier.');
        }
        $tin = trim((string) ($row['supplier_tin'] ?? '')) ?: null;
        $name = trim((string) ($row['supplier'] ?? ''));
        $found = ($tin ? Supplier::where('tin', $tin)->first() : null) ?? ($name !== '' ? Supplier::where('name', $name)->first() : null);
        if ($found) {
            return $found;
        }
        if ($name === '') {
            $this->fail('Give the supplier.');
        }

        return app(ManageSuppliers::class)->save(null, ['name' => $name, 'tin' => $tin, 'kind' => 'company'], $by);
    }

    private function customer(array $row): Customer
    {
        if (! empty($row['customer_id'])) {
            return Customer::find($row['customer_id']) ?? $this->fail('Unknown customer.');
        }
        $tin = trim((string) ($row['customer_tin'] ?? '')) ?: null;
        $name = trim((string) ($row['customer'] ?? ''));
        $found = ($tin ? Customer::where('tin', $tin)->first() : null) ?? ($name !== '' ? Customer::where('name', $name)->first() : null);
        if ($found) {
            return $found;
        }
        if ($name === '') {
            $this->fail('Give the customer.');
        }

        return app(ManageCustomers::class)->save(null, ['name' => $name, 'tin' => $tin, 'kind' => 'company']);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            $this->fail("{$value} is not a date.");
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public function removeRow(OpeningBalance $batch, OpeningBalanceLine $line): void
    {
        $this->editable($batch);
        abort_unless($line->opening_balance_id === $batch->id, 404);
        $line->delete();
    }

    public function delete(OpeningBalance $batch): void
    {
        if ($batch->status !== 'draft') {
            throw ValidationException::withMessages(['batch' => 'Only a draft can be deleted.']);
        }
        $batch->delete();
    }

    /** Opening balance of each account from approved account balances, on its normal side. */
    public function openingOf(array $accountIds): array
    {
        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');

        return OpeningBalanceLine::where('kind', 'accounts')->whereIn('account_id', $accountIds)->whereHas('batch', fn ($b) => $b->where('status', 'approved'))
            ->groupBy('account_id')->selectRaw('account_id, SUM(debit) as d, SUM(credit) as c')->get()
            ->mapWithKeys(fn ($r) => [$r->account_id => $accounts[$r->account_id]->balance((float) $r->d, (float) $r->c)])->all();
    }

    /** @return list<string> */
    public function problems(OpeningBalance $batch): array
    {
        $batch->load('lines.account');
        if ($batch->lines->isEmpty()) {
            return ['Add at least one row.'];
        }
        if ($batch->kind === 'accounts') {
            $d = round($batch->lines->sum('debit'), 2);
            $c = round($batch->lines->sum('credit'), 2);

            return $d === $c ? [] : ['Debits ('.number_format($d, 2).') and credits ('.number_format($c, 2).') must be equal.'];
        }
        $out = [];
        $opening = $this->openingOf($batch->lines->pluck('account_id')->unique()->all());
        foreach ($batch->lines->groupBy('account_id') as $accountId => $lines) {
            $account = $lines->first()->account;
            $mine = round($lines->sum(fn ($l) => $l->amount()), 2);
            $others = round((float) OpeningBalanceLine::where('kind', $batch->kind)->where('account_id', $accountId)->where('opening_balance_id', '!=', $batch->id)
                ->whereHas('batch', fn ($b) => $b->whereIn('status', ['pending', 'approved']))->get()->sum(fn ($l) => $l->amount()), 2);
            $limit = $opening[$accountId] ?? null;
            if ($limit === null) {
                $out[] = "{$account->label()}: load and approve its account balance first.";
            } elseif (round($mine + $others, 2) > round($limit, 2)) {
                $out[] = "{$account->label()}: the rows total ".number_format($mine + $others, 2).' but the opening balance is '.number_format($limit, 2).'.';
            }
        }

        return $out;
    }

    public function submit(OpeningBalance $batch, User $by): OpeningBalance
    {
        $this->editable($batch);
        if ($problems = $this->problems($batch)) {
            throw ValidationException::withMessages(['batch' => $problems]);
        }

        return DB::transaction(function () use ($batch, $by) {
            $batch->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(OpeningBalanceProcess::KEY, $batch, $by)) {
                $this->approved($batch);
            }

            return $batch->refresh();
        });
    }

    public function approved(OpeningBalance $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch->load(['lines.account', 'creator']);
            $by = $batch->creator;
            $journal = null;
            if ($batch->kind === 'accounts') {
                $journal = $this->posting->post($batch->date->toDateString(), "{$batch->number} Opening balances", $batch->lines->map(fn (OpeningBalanceLine $l) => [
                    'account_id' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'description' => trim($l->reference.' '.($l->description ?? '')),
                    'department_id' => $l->department_id, 'fund_source_id' => $l->fund_source_id, 'task_activity_id' => $l->task_activity_id,
                ])->all(), $by, 'opening', $batch);
            }
            foreach ($batch->lines as $l) {
                $target = match ($batch->kind) {
                    'creditors' => $this->bill($batch, $l),
                    'debtors' => $this->invoice($batch, $l),
                    default => null,
                };
                if ($target) {
                    $l->target()->associate($target)->save();
                }
            }
            $batch->update(['status' => 'approved', 'journal_entry_id' => $journal?->id, 'decided_at' => now()]);
        });
    }

    private function bill(OpeningBalance $batch, OpeningBalanceLine $l): PaymentRequest
    {
        $supplier = Supplier::with('bankAccounts')->find($l->supplier_id);
        $bank = $supplier->bankAccounts->where('status', 'approved')->sortByDesc('is_default')->first();
        $r = PaymentRequest::create(['number' => Numbering::next(PaymentRequest::class, 'PR'), 'budget_id' => null, 'department_id' => $l->department_id,
            'title' => "Opening balance {$l->reference} ({$supplier->name})", 'purpose' => $l->description ?: "Unpaid bill {$l->reference} brought forward from the old books.",
            'needed_by' => $l->due_date, 'total' => $l->credit, 'status' => 'approved', 'requested_by' => $batch->created_by, 'submitted_at' => now(), 'decided_at' => now(),
            'source_type' => $l->getMorphClass(), 'source_id' => $l->id, 'supplier_id' => $supplier->id]);
        $r->lines()->create(['budget_item_id' => null, 'account_id' => $l->account_id, 'description' => "Opening balance {$l->reference}", 'amount' => $l->credit]);
        $r->payees()->create(['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
            'account_number' => $bank?->account_number, 'amount' => $l->credit]);

        return $r;
    }

    private function invoice(OpeningBalance $batch, OpeningBalanceLine $l): Invoice
    {
        $date = ($l->document_date ?? $batch->date)->toDateString();
        $invoice = Invoice::create(['number' => Numbering::next(Invoice::class, 'INV', $date), 'customer_id' => $l->customer_id, 'date' => $date,
            'due_date' => ($l->due_date ?? $l->document_date ?? $batch->date)->toDateString(), 'reference' => $l->reference, 'notes' => 'Opening balance brought forward from the old books.',
            'total' => $l->debit, 'status' => 'open', 'created_by' => $batch->created_by, 'issued_at' => now()]);
        $invoice->lines()->create(['description' => $l->description ?: "Opening balance {$l->reference}", 'account_id' => $l->account_id, 'department_id' => $l->department_id,
            'quantity' => 1, 'unit_price' => $l->debit, 'amount' => $l->debit]);

        return $invoice;
    }

    public function rejected(OpeningBalance $batch, ?string $comment, bool $withdrawn): void
    {
        $batch->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    public function withdraw(OpeningBalance $batch, User $by): OpeningBalance
    {
        if ($batch->status !== 'pending' || $batch->created_by !== $by->id) {
            throw ValidationException::withMessages(['batch' => 'Only the person who submitted it can withdraw it while it waits.']);
        }
        $instance = $batch->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($batch, null, true);

        return $batch->refresh();
    }

    /**
     * Each control account against the detail loaded under it.
     *
     * @return list<array{account_id: int, code: string, account: string, kind: string, kind_label: string, opening: float, approved: float, in_progress: float, difference: float}>
     */
    public function reconciliation(): array
    {
        $lines = OpeningBalanceLine::with(['account', 'batch'])->where('kind', '!=', 'accounts')->get();
        $opening = $this->openingOf($lines->pluck('account_id')->unique()->all());

        return $lines->groupBy(fn ($l) => $l->account_id.'|'.$l->kind)->map(function ($g) use ($opening) {
            $first = $g->first();
            $approved = round($g->filter(fn ($l) => $l->batch->status === 'approved')->sum(fn ($l) => $l->amount()), 2);
            $open = round((float) ($opening[$first->account_id] ?? 0), 2);

            return ['account_id' => $first->account_id, 'code' => $first->account->code, 'account' => $first->account->name, 'kind' => $first->kind,
                'kind_label' => OpeningBalance::KINDS[$first->kind], 'opening' => $open, 'approved' => $approved,
                'in_progress' => round($g->filter(fn ($l) => $l->batch->status !== 'approved')->sum(fn ($l) => $l->amount()), 2), 'difference' => round($open - $approved, 2)];
        })->sortBy('code')->values()->all();
    }
}
