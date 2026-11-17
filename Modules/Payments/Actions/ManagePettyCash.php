<?php

namespace Modules\Payments\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Payments\Models\PaymentSetting;
use Modules\Payments\Models\PettyCashFloat;
use Modules\Payments\Models\PettyCashReplenishment;
use Modules\Payments\Models\PettyCashVoucher;
use Modules\Payments\Support\PaymentFiles;
use Modules\Payments\Workflow\PettyCashReplenishmentProcess;
use Modules\Payments\Workflow\PettyCashVoucherProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Petty cash on the imprest system: a fixed float per cash account, small payments charged to budget lines, topped up back to the float from the bank. */
class ManagePettyCash
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly PostingService $posting, private readonly ManageLists $lists) {}

    public function saveFloat(?PettyCashFloat $float, array $data): PettyCashFloat
    {
        $cash = CashBankAccount::whereNotNull('account_id')->find($data['cash_bank_account_id']);
        if (! $cash || $cash->kind !== 'cash') {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose a cash account (kind cash) linked to a ledger account.']);
        }
        if (PettyCashFloat::where('cash_bank_account_id', $cash->id)->when($float, fn ($q) => $q->where('id', '!=', $float->id))->exists()) {
            throw ValidationException::withMessages(['cash_bank_account_id' => "{$cash->name} already has a petty cash float."]);
        }
        if (! empty($data['replenish_from_id']) && (int) $data['replenish_from_id'] === $cash->id) {
            throw ValidationException::withMessages(['replenish_from_id' => 'Top up from a different account.']);
        }
        if (! empty($data['max_voucher']) && (float) $data['max_voucher'] > (float) $data['float_amount']) {
            throw ValidationException::withMessages(['max_voucher' => 'The most for one voucher cannot be more than the float.']);
        }
        $float ??= new PettyCashFloat;
        $float->fill(['cash_bank_account_id' => $cash->id, 'custodian_id' => $data['custodian_id'], 'float_amount' => round((float) $data['float_amount'], 2),
            'max_voucher' => ! empty($data['max_voucher']) ? round((float) $data['max_voucher'], 2) : null, 'replenish_from_id' => $data['replenish_from_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true)])->save();

        return $float->refresh();
    }

    public function balance(PettyCashFloat $float): float
    {
        return $this->lists->balance($float->cash);
    }

    public function available(PettyCashFloat $float): float
    {
        return round($this->balance($float) - (float) $float->vouchers()->where('status', 'pending')->sum('amount'), 2);
    }

    public function createVoucher(PettyCashFloat $float, array $data, ?UploadedFile $receipt, User $by): PettyCashVoucher
    {
        if (! $float->is_active) {
            throw ValidationException::withMessages(['float' => 'This petty cash float is closed.']);
        }
        $amount = round((float) $data['amount'], 2);
        if ($float->max_voucher && $amount > $float->max_voucher) {
            throw ValidationException::withMessages(['amount' => 'One petty cash voucher may be at most '.number_format($float->max_voucher, 2).'. Use a payment request for more.']);
        }
        $available = $this->available($float);
        if ($amount > $available) {
            throw ValidationException::withMessages(['amount' => 'Only '.number_format($available, 2).' is left in petty cash. Ask for a top-up first.']);
        }
        if (! $receipt && PaymentSetting::current()->require_attachment) {
            throw ValidationException::withMessages(['receipt' => 'Attach the receipt.']);
        }
        $item = BudgetItem::with('budget')->find($data['budget_item_id']);
        if (! $item || $item->budget?->status !== 'approved') {
            throw ValidationException::withMessages(['budget_item_id' => 'Choose a line of the approved budget.']);
        }
        $mode = $this->budgets->mode();

        return DB::transaction(function () use ($float, $data, $receipt, $by, $amount, $item, $mode) {
            $v = PettyCashVoucher::create(['number' => Numbering::next(PettyCashVoucher::class, 'PCV', $data['date']), 'petty_cash_float_id' => $float->id, 'date' => $data['date'],
                'payee' => trim($data['payee']), 'description' => trim($data['description']), 'budget_item_id' => $item->id, 'amount' => $amount, 'status' => 'pending', 'created_by' => $by->id]);
            if ($receipt) {
                $name = Str::uuid().'.'.strtolower($receipt->getClientOriginalExtension() ?: $receipt->extension());
                $v->update(['receipt_path' => $receipt->storeAs("payments/{$float->company_id}/petty-cash/{$float->id}", $name, PaymentFiles::DISK),
                    'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 200)]);
            }
            $short = $this->budgets->reserve($v, [$item->id => $amount], $mode !== 'block', $by);
            $v->update(['over_budget' => $short !== [] && $mode === 'warn']);
            if (! $this->workflow->start(PettyCashVoucherProcess::KEY, $v, $by)) {
                $this->voucherApproved($v);
            }

            return $v->refresh();
        });
    }

    public function voucherApproved(PettyCashVoucher $v): void
    {
        DB::transaction(function () use ($v) {
            $v->load(['item', 'float.cash', 'creator']);
            $item = $v->item;
            $journal = $this->posting->post($v->date->toDateString(), "{$v->number} petty cash: {$v->payee} — {$v->description}", [
                ['account_id' => $item->account_id, 'debit' => $v->amount, 'description' => $v->description, 'department_id' => $item->department_id,
                    'fund_source_id' => $item->fund_source_id, 'task_activity_id' => $item->task_activity_id, 'budget_item_id' => $item->id],
                ['account_id' => $v->float->cash->account_id, 'credit' => $v->amount, 'description' => "Paid to {$v->payee}"],
            ], $v->creator, 'petty_cash', $v);
            $this->budgets->release($v, $v->creator);
            $v->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
        });
    }

    public function voucherRejected(PettyCashVoucher $v, ?string $comment, bool $withdrawn): void
    {
        DB::transaction(function () use ($v, $comment, $withdrawn) {
            $this->budgets->release($v);
            $v->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        });
        if (! $withdrawn) {
            $v->creator?->notify(new Notice('Petty cash voucher not approved', "{$v->number} was not approved: {$comment}", '/payments/petty-cash', 'cash'));
        }
    }

    public function withdrawVoucher(PettyCashVoucher $v, User $by): void
    {
        if ($v->status !== 'pending' || $v->created_by !== $by->id) {
            throw ValidationException::withMessages(['voucher' => 'Only your voucher waiting for approval can be withdrawn.']);
        }
        $instance = $v->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->voucherRejected($v, null, true);
    }

    public function replenish(PettyCashFloat $float, array $data, User $by): PettyCashReplenishment
    {
        if ($float->replenishments()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['float' => 'A top-up of this float is already waiting for approval.']);
        }
        $from = CashBankAccount::where('is_active', true)->whereNotNull('account_id')->find($data['from_account_id'] ?? $float->replenish_from_id);
        if (! $from || $from->id === $float->cash_bank_account_id) {
            throw ValidationException::withMessages(['from_account_id' => 'Choose the bank account to top up from.']);
        }
        $amount = round((float) ($data['amount'] ?? 0) ?: $float->float_amount - $this->balance($float), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Petty cash is already at its float.']);
        }
        if (round($this->balance($float) + $amount, 2) > $float->float_amount) {
            throw ValidationException::withMessages(['amount' => 'A top-up cannot take petty cash above its float of '.number_format($float->float_amount, 2).'.']);
        }
        $this->ensureFunds($from, $amount);

        return DB::transaction(function () use ($float, $data, $by, $from, $amount) {
            $r = PettyCashReplenishment::create(['number' => Numbering::next(PettyCashReplenishment::class, 'PCR', $data['date']), 'petty_cash_float_id' => $float->id,
                'from_account_id' => $from->id, 'date' => $data['date'], 'amount' => $amount, 'status' => 'pending', 'created_by' => $by->id]);
            if (! $this->workflow->start(PettyCashReplenishmentProcess::KEY, $r, $by)) {
                $this->replenishmentApproved($r);
            }

            return $r->refresh();
        });
    }

    public function replenishmentApproved(PettyCashReplenishment $r): void
    {
        DB::transaction(function () use ($r) {
            $r->load(['float.cash', 'from', 'creator']);
            $this->ensureFunds($r->from, $r->amount);
            $journal = $this->posting->post($r->date->toDateString(), "{$r->number} petty cash top-up: {$r->float->cash->name} from {$r->from->name}", [
                ['account_id' => $r->float->cash->account_id, 'debit' => $r->amount, 'description' => 'Petty cash top-up'],
                ['account_id' => $r->from->account_id, 'credit' => $r->amount, 'description' => $r->number],
            ], $r->creator, 'petty_cash', $r);
            $r->float->vouchers()->where('status', 'approved')->whereNull('petty_cash_replenishment_id')->update(['petty_cash_replenishment_id' => $r->id]);
            $r->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
        });
        $r->float->custodian?->notify(new Notice('Petty cash topped up', "{$r->number}: ".number_format($r->amount, 2)." added to {$r->float->cash->name}.", '/payments/petty-cash', 'cash'));
    }

    public function replenishmentRejected(PettyCashReplenishment $r, ?string $comment, bool $withdrawn): void
    {
        $r->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $r->creator?->notify(new Notice('Petty cash top-up not approved', "{$r->number} was not approved: {$comment}", '/payments/petty-cash', 'cash'));
        }
    }

    public function withdrawReplenishment(PettyCashReplenishment $r, User $by): void
    {
        if ($r->status !== 'pending' || $r->created_by !== $by->id) {
            throw ValidationException::withMessages(['replenishment' => 'Only your top-up waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->replenishmentRejected($r, null, true);
    }

    private function ensureFunds(CashBankAccount $from, float $amount): void
    {
        $balance = $this->lists->balance($from);
        if ($amount > $balance) {
            throw ValidationException::withMessages(['from_account_id' => "{$from->name} has ".number_format($balance, 2).', less than '.number_format($amount, 2).'.']);
        }
    }
}
