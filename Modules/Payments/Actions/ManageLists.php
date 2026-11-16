<?php

namespace Modules\Payments\Actions;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Notifications\Notice;
use Modules\Payments\Models\PaymentList;
use Modules\Payments\Models\PaymentListItem;
use Modules\Payments\Models\PaymentSetting;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Core\Support\Numbering;
use Modules\Payments\Workflow\PaymentListProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageLists
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting) {}

    public function balance(CashBankAccount $bank): float
    {
        if (! $bank->account_id) {
            return 0;
        }
        $row = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('l.account_id', $bank->account_id)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    public function create(array $data, User $by): PaymentList
    {
        $bank = CashBankAccount::where('is_active', true)->findOrFail($data['cash_bank_account_id']);
        $vouchers = PaymentVoucher::with('request.payees')->whereIn('id', $data['voucher_ids'])->get();
        if ($vouchers->count() !== count(array_unique($data['voucher_ids']))) {
            throw ValidationException::withMessages(['voucher_ids' => 'Some vouchers were not found.']);
        }
        $payees = [];
        foreach ($vouchers as $v) {
            $payees[$v->id] = $v->status === 'approved' && $v->cash_bank_account_id === $bank->id ? $v->outstandingPayees() : collect();
            if ($payees[$v->id]->isEmpty()) {
                throw ValidationException::withMessages(['voucher_ids' => "{$v->number} is not an approved voucher of {$bank->name} with payees still to pay."]);
            }
        }
        $total = round(collect($payees)->flatten()->sum(fn ($p) => $p->net()), 2);
        $this->ensureFunds($bank, $total);

        return DB::transaction(function () use ($data, $by, $bank, $vouchers, $total, $payees) {
            $list = PaymentList::create(['number' => Numbering::next(PaymentList::class, 'PL', $data['date']), 'cash_bank_account_id' => $bank->id,
                'method' => $data['method'], 'date' => $data['date'], 'total' => $total, 'status' => 'pending', 'prepared_by' => $by->id]);
            foreach ($vouchers as $v) {
                foreach ($payees[$v->id] as $p) {
                    $list->items()->create(['payment_voucher_id' => $v->id, 'payment_request_payee_id' => $p->id, 'amount' => $p->net(), 'status' => 'paid']);
                }
            }
            if (! $this->workflow->start(PaymentListProcess::KEY, $list, $by)) {
                $this->approved($list);
            }

            return $list->refresh();
        });
    }

    public function approved(PaymentList $list): void
    {
        DB::transaction(function () use ($list) {
            $list->load(['items.voucher.request', 'bank', 'preparer']);
            $this->ensureFunds($list->bank, $list->total);
            $payable = PaymentSetting::current()->payable_account_id;
            $journal = $this->posting->post($list->date->toDateString(), "{$list->number} payments from {$list->bank->name}", [
                ['account_id' => $payable, 'debit' => $list->total, 'description' => 'Payables settled: '.$list->items->pluck('voucher.number')->unique()->join(', ')],
                ['account_id' => $list->bank->account_id, 'credit' => $list->total, 'description' => $list->number],
            ], $list->preparer, 'payment_list', $list);
            $list->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
            $list->items()->update(['settled_at' => now()]);
            foreach ($list->items->pluck('voucher')->unique('id') as $v) {
                $this->settle($v);
                if ($v->status === 'paid') {
                    $v->request->requester?->notify(new Notice('Payment made', "{$v->request->number} {$v->request->title} is paid.", "/payments/requests/{$v->request->id}", 'cash-coin'));
                }
            }
        });
    }

    public function rejected(PaymentList $list, ?string $comment, bool $withdrawn): void
    {
        $list->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $list->preparer?->notify(new Notice('Payment list not approved', "{$list->number} was not approved: {$comment}", "/payments/lists/{$list->id}", 'bank'));
        }
    }

    public function withdraw(PaymentList $list, User $by): PaymentList
    {
        if ($list->status !== 'pending' || $list->prepared_by !== $by->id) {
            throw ValidationException::withMessages(['list' => 'Only your payment list waiting for approval can be withdrawn.']);
        }
        $instance = $list->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($list, null, true);

        return $list->refresh();
    }

    /** @param array<int, string|null> $references item id => bank reference */
    public function references(PaymentList $list, array $references): PaymentList
    {
        if ($list->status !== 'approved') {
            throw ValidationException::withMessages(['list' => 'Bank references are entered after the list is approved.']);
        }
        foreach ($list->items as $item) {
            if (array_key_exists($item->id, $references)) {
                $item->update(['reference' => $references[$item->id] ?: null]);
            }
        }

        return $list->refresh();
    }

    public function markFailed(PaymentList $list, PaymentListItem $item, string $status, string $reason, User $by): PaymentList
    {
        if ($list->status !== 'approved' || $item->payment_list_id !== $list->id || $item->status !== 'paid') {
            throw ValidationException::withMessages(['item' => 'Only a paid line of an approved list can be marked failed or returned.']);
        }
        DB::transaction(function () use ($list, $item, $status, $reason, $by) {
            $list->load('bank');
            $payee = $item->payee;
            $journal = $this->posting->post(now()->toDateString(), "{$list->number} payment to {$payee?->name} ".($status === 'failed' ? 'failed' : 'returned').": {$reason}", [
                ['account_id' => $list->bank->account_id, 'debit' => $item->amount, 'description' => $list->number],
                ['account_id' => PaymentSetting::current()->payable_account_id, 'credit' => $item->amount, 'description' => 'Payable again: '.$payee?->name],
            ], $by, 'payment_list', $list);
            $item->update(['status' => $status, 'failure_reason' => $reason, 'reversal_journal_id' => $journal->id]);
            $this->settle($item->voucher);
        });

        return $list->refresh();
    }

    public function settle(PaymentVoucher $v): void
    {
        $v->refresh();
        if (! in_array($v->status, ['approved', 'paid'], true)) {
            return;
        }
        $status = $v->settledStatus();
        $v->update(['status' => $status]);
        $v->request->update(['status' => $status === 'paid' ? 'paid' : 'vouchered']);
    }

    private function ensureFunds(CashBankAccount $bank, float $total): void
    {
        $balance = $this->balance($bank);
        if (round($total, 2) > $balance) {
            throw ValidationException::withMessages(['cash_bank_account_id' => "{$bank->name} has ".number_format($balance, 2).', less than the '.number_format($total, 2).' on this list.']);
        }
    }
}
