<?php

namespace Modules\Payments\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentSetting;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Core\Support\Numbering;
use Modules\Payments\Workflow\PaymentVoidProcess;
use Modules\Payments\Workflow\PaymentVoucherProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageVouchers
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly PostingService $posting) {}

    public function create(PaymentRequest $r, array $data, User $by): PaymentVoucher
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['request' => 'A voucher is prepared from an approved request that has no voucher yet.']);
        }
        if ($r->source_type && $r->source_type === (new \Modules\Procurement\Models\GoodsReceipt)->getMorphClass()) {
            $grn = \Modules\Procurement\Models\GoodsReceipt::find($r->source_id);
            if (! $grn || ! app(\Modules\Procurement\Actions\ManageReceipts::class)->matches($grn)) {
                throw ValidationException::withMessages(['request' => 'Record the supplier invoice on the goods received note first; it must match the goods accepted (3-way match).']);
            }
        }
        $settings = PaymentSetting::current();
        if (! $settings->payable_account_id) {
            throw ValidationException::withMessages(['settings' => 'Choose the payables account in payment settings first.']);
        }
        $bank = CashBankAccount::where('is_active', true)->find($data['cash_bank_account_id']);
        if (! $bank || ! $bank->account_id) {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose an active bank or cash account linked to a ledger account.']);
        }
        $withholding = collect($data['withholding'] ?? [])->mapWithKeys(fn ($w) => [(int) $w['payee_id'] => round((float) $w['amount'], 2)]);
        foreach ($withholding as $payeeId => $amount) {
            $payee = $r->payees->firstWhere('id', $payeeId);
            if (! $payee || $amount < 0 || $amount > $payee->amount) {
                throw ValidationException::withMessages(['withholding' => 'Withholding must be for a payee of this request and not more than the amount paid to them.']);
            }
        }
        $wht = round($withholding->sum(), 2);
        if ($wht > 0 && ! $settings->withholding_account_id) {
            throw ValidationException::withMessages(['settings' => 'Choose the withholding tax account in payment settings first.']);
        }

        return DB::transaction(function () use ($r, $data, $by, $bank, $withholding, $wht) {
            foreach ($r->payees as $p) {
                $p->update(['withholding' => $withholding[$p->id] ?? 0]);
            }
            $v = PaymentVoucher::create([
                'number' => Numbering::next(PaymentVoucher::class, 'PV', $data['date']), 'payment_request_id' => $r->id, 'cash_bank_account_id' => $bank->id,
                'date' => $data['date'], 'description' => $data['description'] ?? $r->title, 'gross' => $r->total, 'withholding' => $wht, 'net' => round($r->total - $wht, 2),
                'status' => 'pending', 'prepared_by' => $by->id,
            ]);
            $r->update(['status' => 'vouchered']);
            if (! $this->workflow->start(PaymentVoucherProcess::KEY, $v, $by)) {
                $this->approved($v);
            }

            return $v->refresh();
        });
    }

    public function approved(PaymentVoucher $v): void
    {
        DB::transaction(function () use ($v) {
            $r = $v->request()->with(['lines.item', 'payees'])->first();
            $settings = PaymentSetting::current();
            $by = $v->preparer;
            $lines = $r->lines->map(fn ($l) => $l->account_id
                ? ['account_id' => $l->account_id, 'debit' => $l->amount, 'description' => $l->description ?: $r->title, 'department_id' => $l->item?->department_id ?? $r->department_id]
                : ['account_id' => $l->item->account_id, 'debit' => $l->amount, 'description' => $l->description ?: $r->title, 'department_id' => $l->item->department_id,
                    'fund_source_id' => $l->item->fund_source_id, 'task_activity_id' => $l->item->task_activity_id, 'budget_item_id' => $l->budget_item_id])->all();
            $lines[] = ['account_id' => $settings->payable_account_id, 'credit' => $v->net, 'description' => 'Payable: '.$r->payees->pluck('name')->join(', ')];
            if ($v->withholding > 0) {
                $lines[] = ['account_id' => $settings->withholding_account_id, 'credit' => $v->withholding, 'description' => 'Withholding tax'];
            }
            $this->budgets->release($r, $by);
            $journal = $this->posting->post($v->date->toDateString(), "{$v->number} {$r->number} {$r->title}", $lines, $by, 'payment', $v);
            $v->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
        });
        $v->preparer?->notify(new Notice('Payment voucher approved', "{$v->number} is approved and can go on a payment list.", "/payments/vouchers/{$v->id}", 'receipt'));
    }

    public function rejected(PaymentVoucher $v, ?string $comment, bool $withdrawn): void
    {
        DB::transaction(function () use ($v, $comment, $withdrawn) {
            $v->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
            $v->request->payees()->update(['withholding' => 0]);
            $v->request->update(['status' => 'approved']);
        });
        if (! $withdrawn) {
            $v->preparer?->notify(new Notice('Payment voucher not approved', "{$v->number} was not approved: {$comment}", "/payments/vouchers/{$v->id}", 'receipt'));
        }
    }

    public function withdraw(PaymentVoucher $v, User $by): PaymentVoucher
    {
        if ($v->status !== 'pending' || $v->prepared_by !== $by->id) {
            throw ValidationException::withMessages(['voucher' => 'Only your voucher waiting for approval can be withdrawn.']);
        }
        $instance = $v->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($v, null, true);

        return $v->refresh();
    }

    public function void(PaymentVoucher $v, User $by, string $reason): PaymentVoucher
    {
        if (! in_array($v->status, ['approved', 'paid'], true) || $v->inOpenList()) {
            throw ValidationException::withMessages(['voucher' => 'Only an approved or paid voucher that is not on a list waiting for approval can be voided.']);
        }
        if (! $v->hasPaidItems()) {
            $this->undo($v, $by, $reason);

            return $v->refresh();
        }

        return DB::transaction(function () use ($v, $by, $reason) {
            $v->update(['status' => 'void_pending', 'decision_comment' => $reason]);
            if (! $this->workflow->start(PaymentVoidProcess::KEY, $v, $by)) {
                $this->voidApproved($v, $by);
            }

            return $v->refresh();
        });
    }

    public function voidApproved(PaymentVoucher $v, ?User $by = null): void
    {
        $by ??= $v->preparer;
        DB::transaction(function () use ($v, $by) {
            $payable = PaymentSetting::current()->payable_account_id;
            $items = $v->listItems()->with(['list.bank', 'payee'])->where('status', 'paid')->whereHas('list', fn ($q) => $q->where('status', 'approved'))->get();
            foreach ($items as $item) {
                $journal = $this->posting->post(now()->toDateString(), "{$v->number} voided: money recovered from {$item->payee?->name}", [
                    ['account_id' => $item->list->bank->account_id, 'debit' => $item->amount, 'description' => $item->list->number],
                    ['account_id' => $payable, 'credit' => $item->amount, 'description' => $item->payee?->name],
                ], $by, 'payment_list', $item->list);
                $item->update(['status' => 'returned', 'failure_reason' => 'Voucher voided: '.$v->decision_comment, 'reversal_journal_id' => $journal->id]);
            }
            $this->undo($v, $by, (string) $v->decision_comment);
        });
    }

    public function voidRejected(PaymentVoucher $v, ?string $comment): void
    {
        $v->update(['status' => $v->outstandingPayees()->isEmpty() ? 'paid' : 'approved']);
        $v->preparer?->notify(new Notice('Void not approved', "Voiding {$v->number} was not approved".($comment ? ": {$comment}" : '.'), "/payments/vouchers/{$v->id}", 'receipt'));
    }

    private function undo(PaymentVoucher $v, User $by, string $reason): void
    {
        DB::transaction(function () use ($v, $by, $reason) {
            $r = $v->request()->with('lines')->first();
            if ($v->journal) {
                $this->posting->reverse($v->journal, now()->toDateString(), $by, "{$v->number} voided: {$reason}");
            }
            foreach ($r->lines->whereNull('account_id') as $l) {
                $this->budgets->record($l->budget_item_id, 'committed', $l->amount, $r, 'Voucher voided', $by);
            }
            $r->payees()->update(['withholding' => 0]);
            $r->update(['status' => 'approved']);
            $v->update(['status' => 'voided', 'decision_comment' => $reason, 'decided_at' => now()]);
        });
    }
}
