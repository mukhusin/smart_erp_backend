<?php

namespace Modules\Payments\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Payments\Models\PaymentAttachment;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentSetting;
use Modules\Core\Support\Numbering;
use Modules\Payments\Support\PaymentFiles;
use Modules\Payments\Workflow\PaymentRequestProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageRequests
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets) {}

    public function save(?PaymentRequest $r, array $data, User $by): PaymentRequest
    {
        if ($r && ! $r->editable()) {
            throw ValidationException::withMessages(['request' => 'Only a draft or returned request can be changed.']);
        }
        $items = BudgetItem::with('budget')->whereIn('id', array_column($data['lines'], 'budget_item_id'))->get()->keyBy('id');
        $budgetIds = $items->pluck('budget_id')->unique();
        if ($items->count() !== count(array_unique(array_column($data['lines'], 'budget_item_id')))) {
            throw ValidationException::withMessages(['lines' => 'Use each budget line once.']);
        }
        if ($budgetIds->count() !== 1 || $items->first()->budget?->status !== 'approved') {
            throw ValidationException::withMessages(['lines' => 'Choose budget lines of one approved budget.']);
        }
        if ($items->contains(fn (BudgetItem $i) => $i->department_id !== (int) $data['department_id'])) {
            throw ValidationException::withMessages(['lines' => 'Every budget line must belong to the department of the request.']);
        }
        $total = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $data['lines'])), 2);
        $payees = round(array_sum(array_map(fn ($p) => (float) $p['amount'], $data['payees'])), 2);
        if ($total !== $payees) {
            throw ValidationException::withMessages(['payees' => 'Payees add up to '.number_format($payees, 2).' but the budget lines add up to '.number_format($total, 2).'.']);
        }
        foreach ($data['payees'] as $i => $p) {
            if ($p['method'] === 'bank' && (empty($p['bank_name']) || empty($p['account_number']))) {
                throw ValidationException::withMessages(["payees.$i.account_number" => 'Payee '.($i + 1).': enter the bank and account number.']);
            }
            if ($p['method'] === 'mobile' && empty($p['mobile_number'])) {
                throw ValidationException::withMessages(["payees.$i.mobile_number" => 'Payee '.($i + 1).': enter the mobile number.']);
            }
        }

        return DB::transaction(function () use ($r, $data, $by, $total, $budgetIds) {
            $r ??= new PaymentRequest(['number' => Numbering::next(PaymentRequest::class, 'PR'), 'requested_by' => $by->id, 'status' => 'draft']);
            $r->fill(['budget_id' => $budgetIds->first(), 'department_id' => $data['department_id'], 'title' => $data['title'], 'purpose' => $data['purpose'] ?? null,
                'needed_by' => $data['needed_by'] ?? null, 'total' => $total])->save();
            $r->lines()->delete();
            foreach ($data['lines'] as $l) {
                $r->lines()->create(['budget_item_id' => $l['budget_item_id'], 'description' => $l['description'] ?? null, 'amount' => round((float) $l['amount'], 2)]);
            }
            $r->payees()->delete();
            foreach ($data['payees'] as $p) {
                $r->payees()->create([
                    'name' => $p['name'], 'kind' => $p['kind'] ?? 'supplier', 'employee_id' => $p['employee_id'] ?? null, 'method' => $p['method'],
                    'bank_name' => $p['method'] === 'bank' ? $p['bank_name'] ?? null : null, 'account_name' => $p['method'] === 'bank' ? $p['account_name'] ?? null : null,
                    'account_number' => $p['method'] === 'bank' ? $p['account_number'] ?? null : null, 'mobile_number' => $p['method'] === 'mobile' ? $p['mobile_number'] ?? null : null,
                    'amount' => round((float) $p['amount'], 2),
                ]);
            }

            return $r->refresh();
        });
    }

    public function submit(PaymentRequest $r, User $by): PaymentRequest
    {
        if (! $r->editable()) {
            throw ValidationException::withMessages(['request' => 'This request is already submitted.']);
        }
        if (PaymentSetting::current()->require_attachment && ! $r->attachments()->exists()) {
            throw ValidationException::withMessages(['attachments' => 'Attach the supporting document (invoice, quotation or memo) before you submit.']);
        }
        $amounts = $r->lines->mapWithKeys(fn ($l) => [$l->budget_item_id => $l->amount])->all();
        $mode = $this->budgets->mode();

        return DB::transaction(function () use ($r, $by, $amounts, $mode) {
            $short = $this->budgets->reserve($r, $amounts, $mode !== 'block', $by);
            $r->update(['status' => 'pending', 'submitted_at' => now(), 'over_budget' => $short !== [] && $mode === 'warn', 'decision_comment' => null, 'decided_at' => null]);
            if (! $this->workflow->start(PaymentRequestProcess::KEY, $r, $by)) {
                $this->approved($r);
            }

            return $r->refresh();
        });
    }

    public function approved(PaymentRequest $r): void
    {
        $this->budgets->commit($r);
        $r->update(['status' => 'approved', 'decided_at' => now()]);
        $r->requester?->notify(new Notice('Payment request approved', "{$r->number} {$r->title} is approved and goes to finance for the voucher.", $this->link($r), 'cash-coin'));
    }

    public function rejected(PaymentRequest $r, ?string $comment, bool $withdrawn): void
    {
        $this->budgets->release($r);
        $r->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $r->requester?->notify(new Notice('Payment request returned', "{$r->number} {$r->title} was returned: {$comment}", $this->link($r), 'cash-coin'));
        }
    }

    public function withdraw(PaymentRequest $r, User $by): PaymentRequest
    {
        if ($r->status !== 'pending' || $r->requested_by !== $by->id) {
            throw ValidationException::withMessages(['request' => 'Only your request waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($r, null, true);

        return $r->refresh();
    }

    public function cancel(PaymentRequest $r, User $by, bool $finance, string $reason): PaymentRequest
    {
        $mine = $r->requested_by === $by->id;
        $allowed = ($r->editable() && ($mine || $finance)) || ($r->status === 'approved' && $finance);
        if (! $allowed) {
            throw ValidationException::withMessages(['request' => $r->status === 'approved' ? 'Only finance can cancel an approved request.' : 'This request cannot be cancelled now.']);
        }
        DB::transaction(function () use ($r, $by, $reason) {
            $this->budgets->release($r, $by);
            $r->update(['status' => 'cancelled', 'decision_comment' => $reason, 'decided_at' => now()]);
        });
        if (! $mine) {
            $r->requester?->notify(new Notice('Payment request cancelled', "{$r->number} {$r->title} was cancelled: {$reason}", $this->link($r), 'cash-coin'));
        }

        return $r->refresh();
    }

    public function delete(PaymentRequest $r, User $by): void
    {
        if ($r->status !== 'draft' || $r->requested_by !== $by->id || $r->vouchers()->exists()) {
            throw ValidationException::withMessages(['request' => 'Only your draft request can be deleted.']);
        }
        DB::transaction(function () use ($r) {
            foreach ($r->attachments as $a) {
                PaymentFiles::delete($a->path);
            }
            $r->delete();
        });
    }

    public function attach(PaymentRequest $r, UploadedFile $file, User $by): PaymentAttachment
    {
        if (! $r->editable()) {
            throw ValidationException::withMessages(['file' => 'Documents are added while the request is a draft or returned.']);
        }

        return $r->attachments()->create(['path' => PaymentFiles::store($file, $r), 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'size' => (int) $file->getSize(), 'uploaded_by' => $by->id]);
    }

    public function detach(PaymentRequest $r, PaymentAttachment $a): void
    {
        if (! $r->editable()) {
            throw ValidationException::withMessages(['file' => 'Documents of a submitted request cannot be removed.']);
        }
        PaymentFiles::delete($a->path);
        $a->delete();
    }

    public function link(PaymentRequest $r): string
    {
        return "/payments/requests/{$r->id}";
    }
}
