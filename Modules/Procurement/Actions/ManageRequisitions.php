<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Support\BudgetLines;
use Modules\Procurement\Workflow\PurchaseRequisitionProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageRequisitions
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets) {}

    public function save(?PurchaseRequisition $r, array $data, User $by): PurchaseRequisition
    {
        if ($r && ! $r->editable()) {
            throw ValidationException::withMessages(['requisition' => 'Only a draft or returned requisition can be changed.']);
        }
        if (($data['kind'] ?? 'normal') === 'special' && empty($data['special_reason'])) {
            throw ValidationException::withMessages(['special_reason' => 'A special requisition needs a written reason.']);
        }
        $items = BudgetLines::check(array_column($data['lines'], 'budget_item_id'), (int) $data['department_id']);

        return DB::transaction(function () use ($r, $data, $by, $items) {
            $lines = array_map(fn ($l) => [
                'item_id' => $l['item_id'] ?? null, 'description' => $l['description'], 'specification' => $l['specification'] ?? null, 'unit_id' => $l['unit_id'] ?? null,
                'quantity' => round((float) $l['quantity'], 4), 'estimated_unit_cost' => round((float) $l['estimated_unit_cost'], 2),
                'amount' => round((float) $l['quantity'] * (float) $l['estimated_unit_cost'], 2), 'budget_item_id' => $l['budget_item_id'],
            ], $data['lines']);
            $r ??= new PurchaseRequisition(['number' => Numbering::next(PurchaseRequisition::class, 'PRQ'), 'requested_by' => $by->id, 'status' => 'draft']);
            $r->fill(collect($data)->only(['department_id', 'store_id', 'title', 'purpose', 'kind', 'special_reason', 'needed_by', 'delivery_place'])->all()
                + ['budget_id' => $items->first()->budget_id, 'total' => round(array_sum(array_column($lines, 'amount')), 2)])->save();
            $r->lines()->delete();
            foreach ($lines as $l) {
                $r->lines()->create($l);
            }

            return $r->refresh();
        });
    }

    public function submit(PurchaseRequisition $r, User $by): PurchaseRequisition
    {
        if (! $r->editable()) {
            throw ValidationException::withMessages(['requisition' => 'This requisition is already submitted.']);
        }
        $mode = $this->budgets->mode();

        return DB::transaction(function () use ($r, $by, $mode) {
            $short = $this->budgets->reserve($r, BudgetLines::amounts($r->lines), $mode !== 'block', $by);
            $r->update(['status' => 'pending', 'submitted_at' => now(), 'over_budget' => $short !== [] && $mode === 'warn', 'decision_comment' => null, 'decided_at' => null]);
            if (! $this->workflow->start(PurchaseRequisitionProcess::KEY, $r, $by)) {
                $this->approved($r);
            }

            return $r->refresh();
        });
    }

    public function approved(PurchaseRequisition $r): void
    {
        $r->update(['status' => 'approved', 'decided_at' => now()]);
        $r->requester?->notify(new Notice('Requisition approved', "{$r->number} {$r->title} is approved and goes to procurement.", "/procurement/requisitions/{$r->id}", 'box-seam'));
    }

    public function rejected(PurchaseRequisition $r, ?string $comment, bool $withdrawn): void
    {
        $this->budgets->release($r);
        $r->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $r->requester?->notify(new Notice('Requisition returned', "{$r->number} {$r->title} was returned: {$comment}", "/procurement/requisitions/{$r->id}", 'box-seam'));
        }
    }

    public function withdraw(PurchaseRequisition $r, User $by): PurchaseRequisition
    {
        if ($r->status !== 'pending' || $r->requested_by !== $by->id) {
            throw ValidationException::withMessages(['requisition' => 'Only your requisition waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($r, null, true);

        return $r->refresh();
    }

    public function cancel(PurchaseRequisition $r, User $by, string $reason): PurchaseRequisition
    {
        if (! in_array($r->status, ['draft', 'rejected', 'approved', 'ordered'], true) || $r->orders()->whereIn('status', ['pending'])->exists()) {
            throw ValidationException::withMessages(['requisition' => 'This requisition cannot be cancelled now.']);
        }
        DB::transaction(function () use ($r, $by, $reason) {
            $this->budgets->release($r, $by);
            $r->update(['status' => $r->lines->sum('ordered_quantity') > 0 ? 'closed' : 'cancelled', 'decision_comment' => $reason, 'decided_at' => now()]);
        });

        return $r->refresh();
    }

    /** Release what is held for the ordered part of each line and close the requisition when everything is ordered. */
    public function ordered(PurchaseRequisition $r, array $orderedByLine, User $by): void
    {
        $held = [];
        foreach ($r->lines as $line) {
            $qty = (float) ($orderedByLine[$line->id] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $line->update(['ordered_quantity' => round($line->ordered_quantity + $qty, 4)]);
            $held[$line->budget_item_id] = ($held[$line->budget_item_id] ?? 0) + round(min($qty, $line->quantity) * $line->estimated_unit_cost, 2);
        }
        foreach ($held as $itemId => $amount) {
            $left = $this->budgets->heldBy($r, $itemId);
            if ($left > 0) {
                $this->budgets->record($itemId, 'reserved', -min($amount, $left), $r, 'Ordered', $by);
            }
        }
        if ($r->lines()->get()->every(fn ($l) => $l->remaining() <= 0)) {
            $this->budgets->release($r, $by);
            $r->update(['status' => 'ordered']);
        }
    }
}
