<?php

namespace Modules\Inventory\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StoreRequisition;
use Modules\Inventory\Workflow\StoreRequisitionProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageStoreRequisitions
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function save(?StoreRequisition $r, array $data, User $by): StoreRequisition
    {
        if ($r && ! $r->editable()) {
            throw ValidationException::withMessages(['requisition' => 'Only a draft or returned request can be changed.']);
        }
        $items = Item::whereIn('id', array_column($data['lines'], 'item_id'))->get()->keyBy('id');
        foreach ($data['lines'] as $i => $l) {
            $item = $items[$l['item_id']] ?? null;
            if (! $item || ! $item->is_active || $item->type !== 'store') {
                throw ValidationException::withMessages(["lines.$i.item_id" => 'Choose an active store item.']);
            }
        }

        return DB::transaction(function () use ($r, $data, $by) {
            $r ??= new StoreRequisition(['number' => Numbering::next(StoreRequisition::class, 'SRQ'), 'requested_by' => $by->id, 'status' => 'draft']);
            $r->fill(collect($data)->only(['store_id', 'department_id', 'task_activity_id', 'purpose', 'needed_by'])->all())->save();
            $r->lines()->delete();
            foreach ($data['lines'] as $l) {
                $r->lines()->create(['item_id' => $l['item_id'], 'unit_id' => $l['unit_id'] ?? null, 'quantity' => round((float) $l['quantity'], 4), 'notes' => $l['notes'] ?? null]);
            }

            return $r->refresh();
        });
    }

    public function submit(StoreRequisition $r, User $by): StoreRequisition
    {
        if (! $r->editable()) {
            throw ValidationException::withMessages(['requisition' => 'This request is already submitted.']);
        }

        return DB::transaction(function () use ($r, $by) {
            $r->update(['status' => 'pending', 'submitted_at' => now(), 'decision_comment' => null]);
            if (! $this->workflow->start(StoreRequisitionProcess::KEY, $r, $by)) {
                $this->approved($r);
            }

            return $r->refresh();
        });
    }

    public function approved(StoreRequisition $r): void
    {
        $r->update(['status' => 'approved', 'decided_at' => now()]);
        $r->requester?->notify(new Notice('Store request approved', "{$r->number} is approved and goes to {$r->store?->name} for issue.", "/self-service/store-requests/{$r->id}", 'box'));
    }

    public function rejected(StoreRequisition $r, ?string $comment, bool $withdrawn): void
    {
        $r->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $r->requester?->notify(new Notice('Store request returned', "{$r->number} was returned: {$comment}", "/self-service/store-requests/{$r->id}", 'box'));
        }
    }

    public function withdraw(StoreRequisition $r, User $by): StoreRequisition
    {
        if ($r->status !== 'pending' || $r->requested_by !== $by->id) {
            throw ValidationException::withMessages(['requisition' => 'Only your request waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($r, null, true);

        return $r->refresh();
    }

    public function cancel(StoreRequisition $r, User $by, bool $store): StoreRequisition
    {
        $mine = $r->requested_by === $by->id;
        if (! (($r->editable() && $mine) || (in_array($r->status, ['approved', 'partial'], true) && ($store || $mine)))) {
            throw ValidationException::withMessages(['requisition' => 'This request cannot be cancelled now.']);
        }
        $r->update(['status' => $r->lines->sum('issued_quantity') > 0 ? 'issued' : 'cancelled']);

        return $r->refresh();
    }
}
