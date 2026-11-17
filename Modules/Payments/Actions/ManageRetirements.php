<?php

namespace Modules\Payments\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Payments\Models\ImprestRetirement;
use Modules\Payments\Models\PaymentAttachment;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentSetting;
use Modules\Payments\Support\PaymentFiles;
use Modules\Payments\Workflow\ImprestRetirementProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageRetirements
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly PostingService $posting) {}

    public function save(PaymentRequest $r, ?ImprestRetirement $ret, array $data, User $by): ImprestRetirement
    {
        if (! $r->isImprest() || $r->status !== 'paid') {
            throw ValidationException::withMessages(['request' => 'Only a paid imprest can be retired.']);
        }
        if ($ret && ! $ret->editable()) {
            throw ValidationException::withMessages(['retirement' => 'Only a draft or returned retirement can be changed.']);
        }
        $open = $r->retirements()->whereIn('status', ['draft', 'rejected', 'pending'])->when($ret, fn ($q) => $q->where('id', '!=', $ret->id))->value('number');
        if ($open) {
            throw ValidationException::withMessages(['retirement' => "Retirement {$open} is already open for this imprest."]);
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => round((float) $l['amount'], 2) > 0));
        $allowed = $r->lines()->pluck('budget_item_id')->filter()->all();
        foreach ($lines as $i => $l) {
            if (! in_array((int) $l['budget_item_id'], $allowed, true)) {
                throw ValidationException::withMessages(["lines.$i.budget_item_id" => 'Line '.($i + 1).': choose one of the budget lines the imprest was given for.']);
            }
        }
        $spent = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
        $advanced = round((float) $r->total, 2);
        if ($spent > $advanced) {
            throw ValidationException::withMessages(['lines' => 'Spent '.number_format($spent, 2).' is more than the imprest of '.number_format($advanced, 2).'. Raise a payment request for the extra.']);
        }
        $returned = round($advanced - $spent, 2);
        $bank = null;
        if ($returned > 0) {
            $bank = CashBankAccount::where('is_active', true)->whereNotNull('account_id')->find($data['cash_bank_account_id'] ?? null);
            if (! $bank) {
                throw ValidationException::withMessages(['cash_bank_account_id' => number_format($returned, 2).' was not spent: choose the bank or cash account it was returned to.']);
            }
        }
        foreach (collect($lines)->groupBy('budget_item_id') as $itemId => $group) {
            $item = BudgetItem::find($itemId);
            $check = $this->budgets->check($item, (float) $group->sum('amount'), $r);
            if ($check['over'] && $check['mode'] === 'block') {
                throw ValidationException::withMessages(['lines' => $item->label().': spent '.number_format((float) $group->sum('amount'), 2).', available '.number_format($check['available'], 2).'.']);
            }
        }

        return DB::transaction(function () use ($r, $ret, $data, $by, $lines, $spent, $advanced, $returned, $bank) {
            $ret ??= new ImprestRetirement(['number' => Numbering::next(ImprestRetirement::class, 'IR', $data['date']), 'payment_request_id' => $r->id, 'prepared_by' => $by->id, 'status' => 'draft']);
            $ret->fill(['date' => $data['date'], 'notes' => $data['notes'] ?? null, 'advanced' => $advanced, 'spent' => $spent, 'returned' => $returned,
                'cash_bank_account_id' => $bank?->id, 'return_reference' => $returned > 0 ? ($data['return_reference'] ?? null) : null])->save();
            $ret->lines()->delete();
            foreach ($lines as $l) {
                $ret->lines()->create(['budget_item_id' => $l['budget_item_id'], 'description' => $l['description'] ?? null, 'amount' => round((float) $l['amount'], 2)]);
            }

            return $ret->refresh();
        });
    }

    public function submit(ImprestRetirement $ret, User $by): ImprestRetirement
    {
        if (! $ret->editable()) {
            throw ValidationException::withMessages(['retirement' => 'This retirement is already submitted.']);
        }
        if ($ret->spent > 0 && PaymentSetting::current()->require_attachment && ! $ret->attachments()->exists()) {
            throw ValidationException::withMessages(['attachments' => 'Attach the receipts for what was spent before you submit.']);
        }

        return DB::transaction(function () use ($ret, $by) {
            $ret->update(['status' => 'pending', 'submitted_at' => now(), 'decision_comment' => null, 'decided_at' => null]);
            if (! $this->workflow->start(ImprestRetirementProcess::KEY, $ret, $by)) {
                $this->approved($ret);
            }

            return $ret->refresh();
        });
    }

    public function approved(ImprestRetirement $ret): void
    {
        DB::transaction(function () use ($ret) {
            $ret->load(['lines.item', 'request.payees', 'bank', 'preparer']);
            $r = $ret->request;
            $by = $ret->preparer;
            $holder = $r->payees->first()?->name;
            $lines = $ret->lines->map(fn ($l) => ['account_id' => $l->item->account_id, 'debit' => $l->amount, 'description' => $l->description ?: $r->title,
                'department_id' => $l->item->department_id, 'fund_source_id' => $l->item->fund_source_id, 'task_activity_id' => $l->item->task_activity_id,
                'budget_item_id' => $l->budget_item_id])->all();
            if ($ret->returned > 0) {
                $lines[] = ['account_id' => $ret->bank->account_id, 'debit' => $ret->returned, 'description' => 'Imprest returned by '.$holder.($ret->return_reference ? " ({$ret->return_reference})" : '')];
            }
            $lines[] = ['account_id' => PaymentSetting::current()->imprest_account_id, 'credit' => $ret->advanced, 'description' => "Imprest {$r->number} retired: {$holder}"];
            $journal = $this->posting->post($ret->date->toDateString(), "{$ret->number} retirement of {$r->number} {$r->title}", $lines, $by, 'imprest', $r);
            $this->budgets->release($r, $by);
            $ret->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
            $r->update(['status' => 'retired']);
        });
        $this->tell($ret, 'Imprest retired', "{$ret->number}: imprest {$ret->request->number} is retired.");
    }

    public function rejected(ImprestRetirement $ret, ?string $comment, bool $withdrawn): void
    {
        $ret->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $this->tell($ret, 'Imprest retirement returned', "{$ret->number} was returned: {$comment}");
        }
    }

    public function withdraw(ImprestRetirement $ret, User $by): ImprestRetirement
    {
        if ($ret->status !== 'pending' || $ret->prepared_by !== $by->id) {
            throw ValidationException::withMessages(['retirement' => 'Only your retirement waiting for approval can be withdrawn.']);
        }
        $instance = $ret->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($ret, null, true);

        return $ret->refresh();
    }

    public function delete(ImprestRetirement $ret): void
    {
        if (! $ret->editable()) {
            throw ValidationException::withMessages(['retirement' => 'Only a draft or returned retirement can be deleted.']);
        }
        DB::transaction(function () use ($ret) {
            foreach ($ret->attachments as $a) {
                PaymentFiles::delete($a->path);
            }
            $ret->delete();
        });
    }

    public function attach(ImprestRetirement $ret, UploadedFile $file, User $by): PaymentAttachment
    {
        if (! $ret->editable()) {
            throw ValidationException::withMessages(['file' => 'Receipts are added while the retirement is a draft or returned.']);
        }

        return $ret->attachments()->create(['company_id' => $ret->company_id, 'payment_request_id' => $ret->payment_request_id, 'path' => PaymentFiles::store($file, $ret->request),
            'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'size' => (int) $file->getSize(), 'uploaded_by' => $by->id]);
    }

    public function detach(ImprestRetirement $ret, PaymentAttachment $a): void
    {
        if (! $ret->editable()) {
            throw ValidationException::withMessages(['file' => 'Receipts of a submitted retirement cannot be removed.']);
        }
        PaymentFiles::delete($a->path);
        $a->delete();
    }

    /** Reminds holders of paid imprests past their retire-by date: the day after, then weekly. */
    public function remind(): int
    {
        $sent = 0;
        $due = PaymentRequest::with(['holder.user', 'requester'])->where('kind', 'imprest')->where('status', 'paid')->whereDate('retire_by', '<', today())
            ->whereDoesntHave('retirements', fn ($q) => $q->where('status', 'pending'))->get();
        foreach ($due as $r) {
            $late = (int) $r->retire_by->diffInDays(today());
            if ($late % 7 !== 1) {
                continue;
            }
            $notice = new Notice('Imprest not retired', "{$r->number} {$r->title} (".number_format((float) $r->total, 2).") was due for retirement on {$r->retire_by->format('d M Y')}. Retire it now.", "/payments/requests/{$r->id}", 'hourglass-split');
            foreach (collect([$r->holder?->user, $r->requester])->filter()->unique('id') as $user) {
                $user->notify($notice);
                $sent++;
            }
        }

        return $sent;
    }

    private function tell(ImprestRetirement $ret, string $title, string $message): void
    {
        $r = $ret->request()->with(['holder.user', 'requester'])->first();
        $notice = new Notice($title, $message, "/payments/requests/{$r->id}", 'receipt');
        foreach (collect([$ret->preparer, $r->holder?->user, $r->requester])->filter()->unique('id') as $user) {
            $user->notify($notice);
        }
    }
}
