<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Actions\ManageReconciliations;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankReconciliation;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Core\Services\AccessService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Accounting: bank reconciliation
 */
class ReconciliationController extends Controller
{
    public function __construct(private readonly ManageReconciliations $actions) {}

    /**
     * Reconciliations, newest statement first, with each bank's last reconciled date.
     *
     * @response array{data: list<array{id: int, number: string, bank: string|null, cash_bank_account_id: int, statement_date: string, statement_balance: float, book_balance: float, status: string, status_label: string, prepared_by: string|null, completed_at: string|null}>, banks: list<array{id: int, name: string, kind: string, last_date: string|null, open: bool}>}
     */
    #[QueryParameter('cash_bank_account_id', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $list = BankReconciliation::with(['bank', 'preparer'])
            ->when($request->query('cash_bank_account_id'), fn ($q, $b) => $q->where('cash_bank_account_id', $b))
            ->orderByDesc('statement_date')->orderByDesc('id')->limit(500)->get();
        $all = BankReconciliation::get(['cash_bank_account_id', 'status', 'statement_date']);

        return response()->json([
            'data' => $list->map(fn (BankReconciliation $r) => ['id' => $r->id, 'number' => $r->number, 'bank' => $r->bank?->name, 'cash_bank_account_id' => $r->cash_bank_account_id,
                'statement_date' => $r->statement_date->toDateString(), 'statement_balance' => $r->statement_balance, 'book_balance' => $r->book_balance, 'status' => $r->status,
                'status_label' => BankReconciliation::STATUS_LABELS[$r->status] ?? $r->status, 'prepared_by' => $r->preparer?->display_name, 'completed_at' => $r->completed_at?->toIso8601String()])->values(),
            'banks' => CashBankAccount::where('is_active', true)->whereNotNull('account_id')->orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'kind' => (string) $b->kind,
                'last_date' => $all->where('cash_bank_account_id', $b->id)->where('status', 'completed')->max(fn ($r) => $r->statement_date->toDateString()),
                'open' => $all->where('cash_bank_account_id', $b->id)->where('status', '!=', 'completed')->isNotEmpty()])->values(),
        ]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'statement_date' => ['required', 'date'],
            /** Closing balance on the bank statement (overdrawn is negative). */
            'statement_balance' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $r = $this->actions->create($data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /**
     * A reconciliation: summary, the bank's ledger lines (cleared or not) and the statement lines.
     *
     * @response array{data: array{id: int, number: string, cash_bank_account_id: int, bank: string|null, statement_date: string, statement_balance: float, notes: string|null, status: string, status_label: string, prepared_by: string|null, decision_comment: string|null, waiting_for: string|null, completed_at: string|null, summary: array{book_balance: float, statement_balance: float, uncleared_payments: float, uncleared_receipts: float, adjusted_bank: float, difference: float, cleared: int, uncleared: int, unmatched_statement: int}, book: list<array{id: int, journal_id: int, date: string, reference_no: string, description: string|null, debit: float, credit: float, cleared: bool}>, statement: list<array{id: int, date: string, description: string|null, reference: string|null, amount: float, journal_entry_line_id: int|null, adjustment: bool}>, can: array{edit: bool, submit: bool, withdraw: bool, reopen: bool, delete: bool}}, accounts: list<array{id: int, code: string, name: string}>}
     */
    public function show(Request $request, BankReconciliation $reconciliation, AccessService $access): JsonResponse
    {
        $r = $reconciliation->load(['bank', 'preparer', 'workflow.steps']);
        $lines = $r->status === 'completed'
            ? JournalEntryLine::with('entry')->where('bank_reconciliation_id', $r->id)->get()->sortBy(fn ($l) => $l->entry->date->format('Ymd').str_pad((string) $l->id, 10, '0', STR_PAD_LEFT))->values()
            : $this->actions->bookLines($r);
        $summary = $r->status === 'completed' ? $this->completedSummary($r) : $this->actions->summary($r, $lines);
        $user = $request->user();
        $edit = $r->editable() && $access->can($user, 'accounting.reconcile');
        $adjustments = JournalEntryLine::whereIn('id', $r->statementLines->pluck('journal_entry_line_id')->filter())->whereHas('entry', fn ($q) => $q->where('entry_type', 'bank')->where('source_type', $r->getMorphClass())->where('source_id', $r->id))->pluck('id')->all();

        return response()->json([
            'data' => [
                'id' => $r->id, 'number' => $r->number, 'cash_bank_account_id' => $r->cash_bank_account_id, 'bank' => $r->bank?->name, 'statement_date' => $r->statement_date->toDateString(),
                'statement_balance' => $r->statement_balance, 'notes' => $r->notes, 'status' => $r->status, 'status_label' => BankReconciliation::STATUS_LABELS[$r->status] ?? $r->status,
                'prepared_by' => $r->preparer?->display_name, 'decision_comment' => $r->decision_comment,
                'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'completed_at' => $r->completed_at?->toIso8601String(),
                'summary' => $summary,
                'book' => $lines->map(fn (JournalEntryLine $l) => ['id' => $l->id, 'journal_id' => $l->journal_entry_id, 'date' => $l->entry->date->toDateString(), 'reference_no' => (string) $l->entry->reference_no,
                    'description' => $l->description ?: $l->entry->description, 'debit' => $l->debit, 'credit' => $l->credit, 'cleared' => $l->bank_reconciliation_id === $r->id])->values(),
                'statement' => $r->statementLines->map(fn (BankStatementLine $s) => ['id' => $s->id, 'date' => $s->date->toDateString(), 'description' => $s->description, 'reference' => $s->reference,
                    'amount' => $s->amount, 'journal_entry_line_id' => $s->journal_entry_line_id, 'adjustment' => in_array($s->journal_entry_line_id, $adjustments, true)])->values(),
                'can' => ['edit' => $edit, 'submit' => $edit, 'withdraw' => $r->status === 'pending' && $r->prepared_by === $user->id,
                    'reopen' => $r->status === 'completed' && $access->can($user, 'accounting.manage'), 'delete' => $edit],
            ],
            'accounts' => $edit ? Account::where('is_active', true)->where('is_postable', true)->where('id', '!=', $r->bank?->account_id)->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values() : [],
        ]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $r = $this->actions->update($reconciliation, $data);

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    public function destroy(BankReconciliation $reconciliation): JsonResponse
    {
        $this->actions->delete($reconciliation);

        return response()->json(['message' => 'Reconciliation deleted.']);
    }

    /** @response array{message: string} */
    public function clear(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'line_ids' => ['required', 'array', 'max:5000'],
            'line_ids.*' => ['integer'],
            /** true = seen on the statement, false = not yet. */
            'cleared' => ['required', 'boolean'],
        ]);
        $this->actions->clear($reconciliation, $data['line_ids'], (bool) $data['cleared']);

        return response()->json(['message' => 'Saved.']);
    }

    /** @response array{data: array{added: int, errors: list<string>}} */
    public function import(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        $request->validate([
            /** CSV with columns date, description, reference and amount (money in positive, out negative), or money_in and money_out. */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)))), fgetcsv($handle) ?: []);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
        }
        fclose($handle);
        abort_if($header && ! in_array('date', $header, true), 422, 'The file needs a "date" column. Download the template.');

        return response()->json(['data' => $this->actions->import($reconciliation, $rows, $request->user())]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'description', 'reference', 'money_in', 'money_out']);
            fputcsv($out, [now()->toDateString(), 'Customer deposit', 'DEP123', '150000', '']);
            fputcsv($out, [now()->toDateString(), 'Bank charges', '', '', '2500']);
            fclose($out);
        }, 'bank-statement-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function destroyStatementLine(BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        $this->actions->removeStatementLine($reconciliation, $line);

        return response()->json(['message' => 'Statement line removed.']);
    }

    /** @response array{data: array{matched: int}} */
    public function autoMatch(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        return response()->json(['data' => ['matched' => $this->actions->autoMatch($reconciliation)]]);
    }

    /** @response array{message: string} */
    public function match(Request $request, BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        $data = $request->validate([
            /** Ledger line to pair with; empty to unpair. */
            'journal_entry_line_id' => ['nullable', 'integer'],
        ]);
        $this->actions->match($reconciliation, $line, $data['journal_entry_line_id'] ?? null);

        return response()->json(['message' => 'Saved.']);
    }

    /** @response array{message: string} */
    public function adjust(Request $request, BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        $data = $request->validate([
            /** The other side: bank charges, interest income, a customer, etc. */
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);
        $this->actions->adjust($reconciliation, $line, (int) $data['account_id'], $data['description'] ?? null, $request->user());

        return response()->json(['message' => 'Booked and matched.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        $r = $this->actions->submit($reconciliation, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        $r = $this->actions->withdraw($reconciliation, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function reopen(BankReconciliation $reconciliation): JsonResponse
    {
        $r = $this->actions->reopen($reconciliation);

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** Figures of a completed reconciliation, as they were when it was completed. */
    private function completedSummary(BankReconciliation $r): array
    {
        $outstanding = JournalEntryLine::where('account_id', $r->bank->account_id)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->whereDate('date', '<=', $r->statement_date))
            ->where(fn ($q) => $q->whereNull('bank_reconciliation_id')->orWhereIn('bank_reconciliation_id', BankReconciliation::where('cash_bank_account_id', $r->cash_bank_account_id)
                ->where(fn ($q) => $q->where('statement_date', '>', $r->statement_date)->orWhere('status', '!=', 'completed'))->select('id')))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $payments = round((float) $outstanding->c, 2);
        $receipts = round((float) $outstanding->d, 2);
        $adjusted = round($r->statement_balance - $payments + $receipts, 2);
        $book = $this->actions->bookBalance($r->bank, $r->statement_date->toDateString());

        return ['book_balance' => $book, 'statement_balance' => $r->statement_balance, 'uncleared_payments' => $payments, 'uncleared_receipts' => $receipts,
            'adjusted_bank' => $adjusted, 'difference' => round($adjusted - $book, 2), 'cleared' => $r->clearedLines()->count(), 'uncleared' => 0,
            'unmatched_statement' => $r->statementLines()->whereNull('journal_entry_line_id')->count()];
    }
}
