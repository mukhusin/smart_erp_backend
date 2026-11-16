<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Workflow\PurchaseOrderProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageOrders
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly ManageRequisitions $requisitions) {}

    /** A purchase order without quotations: direct purchase (under the limit), single source or framework call-off (with a reason). */
    public function direct(PurchaseRequisition $r, array $data, User $by): PurchaseOrder
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['requisition' => 'Orders are made from an approved requisition.']);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if (! $supplier->usable()) {
            throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} is inactive or blacklisted."]);
        }
        if (in_array($data['method'], ['single', 'framework'], true) && empty($data['method_reason'])) {
            throw ValidationException::withMessages(['method_reason' => 'Give the reason for not asking for quotations.']);
        }
        $lines = $r->lines->keyBy('id');
        $rows = [];
        foreach ($data['lines'] as $i => $l) {
            $line = $lines[$l['purchase_requisition_line_id']] ?? null;
            if (! $line || $l['quantity'] > $line->remaining() + 0.0001) {
                throw ValidationException::withMessages(["lines.$i.quantity" => 'Order at most what is still to order on the requisition line.']);
            }
            $rows[] = ['purchase_requisition_line_id' => $line->id, 'item_id' => $line->item_id, 'description' => $line->description, 'unit_id' => $line->unit_id,
                'quantity' => round((float) $l['quantity'], 4), 'unit_price' => round((float) $l['unit_price'], 2), 'amount' => round((float) $l['quantity'] * (float) $l['unit_price'], 2),
                'budget_item_id' => $line->budget_item_id];
        }
        $total = round(array_sum(array_column($rows, 'amount')), 2);
        if ($data['method'] === 'direct' && $total > ProcurementSetting::current()->direct_limit) {
            throw ValidationException::withMessages(['method' => 'Above the direct purchase limit of '.number_format(ProcurementSetting::current()->direct_limit, 2).'. Ask for quotations.']);
        }

        return DB::transaction(function () use ($r, $data, $by, $supplier, $rows, $total) {
            $po = PurchaseOrder::create(['number' => Numbering::next(PurchaseOrder::class, 'PO'), 'supplier_id' => $supplier->id, 'purchase_requisition_id' => $r->id, 'store_id' => $r->store_id,
                'method' => $data['method'], 'method_reason' => $data['method_reason'] ?? null, 'order_date' => $data['order_date'] ?? today(), 'delivery_date' => $data['delivery_date'] ?? $r->needed_by,
                'delivery_place' => $r->delivery_place, 'payment_terms_days' => $supplier->payment_terms_days, 'total' => $total, 'status' => 'draft', 'created_by' => $by->id]);
            foreach ($rows as $row) {
                $po->lines()->create($row);
            }

            return $po->refresh();
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['order' => 'Only a draft or returned order can be changed.']);
        }

        return DB::transaction(function () use ($po, $data) {
            $po->update(collect($data)->only(['order_date', 'delivery_date', 'delivery_place', 'payment_terms_days', 'notes', 'store_id'])->all());
            foreach ($data['lines'] ?? [] as $l) {
                $line = $po->lines()->find($l['id']);
                if ($line) {
                    $line->update(['quantity' => round((float) $l['quantity'], 4), 'unit_price' => round((float) $l['unit_price'], 2), 'amount' => round((float) $l['quantity'] * (float) $l['unit_price'], 2)]);
                }
            }
            $po->update(['total' => round((float) $po->lines()->sum('amount'), 2)]);

            return $po->refresh();
        });
    }

    public function submit(PurchaseOrder $po, User $by): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['order' => 'This order is already submitted.']);
        }
        if (! $po->supplier->usable()) {
            throw ValidationException::withMessages(['order' => "{$po->supplier->name} is inactive or blacklisted."]);
        }

        return DB::transaction(function () use ($po, $by) {
            $po->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(PurchaseOrderProcess::KEY, $po, $by)) {
                $this->approved($po);
            }

            return $po->refresh();
        });
    }

    public function approved(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po) {
            $po->load(['lines', 'requisition.lines', 'creator']);
            $by = $po->creator;
            if ($po->requisition) {
                $this->requisitions->ordered($po->requisition, $po->lines->mapWithKeys(fn ($l) => [$l->purchase_requisition_line_id => $l->quantity])->all(), $by);
            }
            $mode = $this->budgets->mode();
            $amounts = [];
            foreach ($po->lines as $l) {
                $amounts[$l->budget_item_id] = round(($amounts[$l->budget_item_id] ?? 0) + $l->amount, 2);
            }
            $this->budgets->reserve($po, $amounts, $mode !== 'block', $by);
            $this->budgets->commit($po, $by);
            $po->update(['status' => 'approved', 'decided_at' => now()]);
        });
        $po->creator?->notify(new Notice('Purchase order approved', "{$po->number} to {$po->supplier->name} is approved. Send it to the supplier.", "/procurement/orders/{$po->id}", 'cart-check'));
    }

    public function rejected(PurchaseOrder $po, ?string $comment, bool $withdrawn): void
    {
        $po->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    public function withdraw(PurchaseOrder $po, User $by): PurchaseOrder
    {
        if ($po->status !== 'pending' || $po->created_by !== $by->id) {
            throw ValidationException::withMessages(['order' => 'Only your order waiting for approval can be withdrawn.']);
        }
        $instance = $po->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($po, null, true);

        return $po->refresh();
    }

    public function acknowledge(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->receivable()) {
            throw ValidationException::withMessages(['order' => 'Only an approved order can be acknowledged.']);
        }
        $po->update(['acknowledged_at' => now()]);

        return $po->refresh();
    }

    public function cancel(PurchaseOrder $po, User $by, string $reason): PurchaseOrder
    {
        if (in_array($po->status, ['pending', 'cancelled', 'closed', 'received'], true) || $po->receipts()->whereIn('status', ['pending', 'accepted'])->exists()) {
            throw ValidationException::withMessages(['order' => 'Only an order with nothing received or waiting can be cancelled.']);
        }
        DB::transaction(function () use ($po, $by, $reason) {
            $this->budgets->release($po, $by);
            foreach ($po->lines()->with('requisitionLine')->get() as $l) {
                if ($l->requisitionLine && in_array($po->status, ['approved', 'partial'], true)) {
                    $l->requisitionLine->update(['ordered_quantity' => max(0, round($l->requisitionLine->ordered_quantity - $l->quantity, 4))]);
                }
            }
            if ($po->requisition && $po->requisition->status === 'ordered') {
                $po->requisition->update(['status' => 'approved']);
            }
            $po->update(['status' => 'cancelled', 'decision_comment' => $reason, 'decided_at' => now()]);
        });

        return $po->refresh();
    }
}
