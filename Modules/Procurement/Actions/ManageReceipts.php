<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Payments\Models\PaymentRequest;
use Modules\Procurement\Events\GoodsAccepted;
use Modules\Procurement\Models\GoodsReceipt;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Workflow\GoodsReceiptProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageReceipts
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly PostingService $posting) {}

    public function create(PurchaseOrder $po, array $data, User $by): GoodsReceipt
    {
        if (! $po->receivable()) {
            throw ValidationException::withMessages(['order' => 'Goods are received against an approved order still waiting for delivery.']);
        }
        if ($po->receipts()->whereIn('status', ['draft', 'pending'])->exists()) {
            throw ValidationException::withMessages(['order' => 'Finish the delivery already being received first.']);
        }
        $lines = $po->lines->keyBy('id');
        $rows = [];
        foreach ($data['lines'] as $i => $l) {
            $line = $lines[$l['purchase_order_line_id']] ?? null;
            $delivered = round((float) $l['delivered_quantity'], 4);
            $accepted = round((float) ($l['accepted_quantity'] ?? $delivered), 4);
            if (! $line || $delivered <= 0) {
                continue;
            }
            if ($accepted > $delivered || $accepted > $line->due() + 0.0001) {
                throw ValidationException::withMessages(["lines.$i.accepted_quantity" => "{$line->description}: accept at most what was delivered and is still due ({$line->due()})."]);
            }
            if ($delivered - $accepted > 0 && empty($l['rejection_reason'])) {
                throw ValidationException::withMessages(["lines.$i.rejection_reason" => "{$line->description}: give the reason for the rejected quantity."]);
            }
            if ($line->item?->track_expiry && $accepted > 0 && empty($l['expiry_date'])) {
                throw ValidationException::withMessages(["lines.$i.expiry_date" => "{$line->description}: enter the expiry date."]);
            }
            $rows[] = ['purchase_order_line_id' => $line->id, 'delivered_quantity' => $delivered, 'accepted_quantity' => $accepted, 'rejected_quantity' => round($delivered - $accepted, 4),
                'rejection_reason' => $l['rejection_reason'] ?? null, 'batch_number' => $l['batch_number'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null,
                'serials' => $l['serials'] ?? null, 'amount' => round($accepted * $line->unit_price, 2)];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['lines' => 'Enter what was delivered.']);
        }

        return DB::transaction(function () use ($po, $data, $by, $rows) {
            $grn = GoodsReceipt::create(['number' => Numbering::next(GoodsReceipt::class, 'GRN', $data['received_on']), 'purchase_order_id' => $po->id, 'store_id' => $data['store_id'] ?? $po->store_id,
                'delivery_note' => $data['delivery_note'] ?? null, 'received_on' => $data['received_on'], 'notes' => $data['notes'] ?? null, 'status' => 'draft',
                'total' => round(array_sum(array_column($rows, 'amount')), 2), 'created_by' => $by->id]);
            foreach ($rows as $row) {
                $grn->lines()->create($row);
            }

            return $grn->refresh();
        });
    }

    public function submit(GoodsReceipt $grn, User $by): GoodsReceipt
    {
        if (! in_array($grn->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['receipt' => 'This delivery is already submitted.']);
        }
        if (! ProcurementSetting::current()->clearing_account_id) {
            throw ValidationException::withMessages(['settings' => 'Choose the goods-received clearing account in procurement settings first.']);
        }

        return DB::transaction(function () use ($grn, $by) {
            $grn->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(GoodsReceiptProcess::KEY, $grn, $by)) {
                $this->accepted($grn);
            }

            return $grn->refresh();
        });
    }

    /** Accepted: stock or asset (or expense) debited against the clearing account, the commitment becomes spent and a supplier bill is raised once. */
    public function accepted(GoodsReceipt $grn): void
    {
        DB::transaction(function () use ($grn) {
            $grn->load(['lines.orderLine.item.category', 'lines.orderLine.budgetItem', 'order.supplier.bankAccounts', 'order.requisition', 'creator']);
            $po = $grn->order;
            $by = $grn->creator;
            $clearing = ProcurementSetting::current()->clearing_account_id;
            $journal = [];
            $byItem = [];
            foreach ($grn->lines as $l) {
                if ($l->amount <= 0) {
                    continue;
                }
                $line = $l->orderLine;
                $category = $line->item?->category;
                $account = match ($category?->type) {
                    'store' => $category->inventory_account_id ?: $category->expense_account_id,
                    'asset', 'intangible', 'wip' => $category->asset_account_id,
                    default => $category?->expense_account_id,
                } ?: $line->budgetItem?->account_id;
                $journal[] = ['account_id' => $account, 'debit' => $l->amount, 'description' => $line->description, 'department_id' => $line->budgetItem?->department_id,
                    'fund_source_id' => $line->budgetItem?->fund_source_id, 'task_activity_id' => $line->budgetItem?->task_activity_id, 'budget_item_id' => $line->budget_item_id];
                $byItem[$line->budget_item_id] = round(($byItem[$line->budget_item_id] ?? 0) + $l->amount, 2);
                $line->update(['received_quantity' => round($line->received_quantity + $l->accepted_quantity, 4)]);
            }
            $total = round(array_sum($byItem), 2);
            $entry = null;
            if ($total > 0) {
                $journal[] = ['account_id' => $clearing, 'credit' => $total, 'description' => "Goods received not yet billed: {$po->supplier->name}"];
                $entry = $this->posting->post($grn->received_on->toDateString(), "{$grn->number} {$po->number} {$po->supplier->name}", $journal, $by, 'goods_receipt', $grn);
                foreach ($byItem as $itemId => $amount) {
                    $committed = $this->budgets->heldBy($po, $itemId);
                    if ($committed > 0) {
                        $this->budgets->record($itemId, 'committed', -min($amount, $committed), $po, 'Delivered', $by);
                    }
                }
            }
            $done = $po->lines()->get()->every(fn ($l) => $l->due() <= 0);
            $po->update(['status' => $done ? 'received' : 'partial']);
            if ($done) {
                $this->budgets->release($po, $by);
            }
            $grn->update(['status' => 'accepted', 'journal_entry_id' => $entry?->id, 'decided_at' => now()]);
            if ($total > 0 && ! $grn->payment_request_id) {
                $grn->update(['payment_request_id' => $this->bill($grn, $byItem, $clearing)->id]);
            }
            event(new GoodsAccepted($grn->refresh()));
        });
        $grn->creator?->notify(new Notice('Delivery accepted', "{$grn->number} is accepted; the supplier bill is with finance.", "/procurement/receipts/{$grn->id}", 'box-seam'));
    }

    public function rejected(GoodsReceipt $grn, ?string $comment, bool $withdrawn): void
    {
        $grn->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    public function withdraw(GoodsReceipt $grn, User $by): GoodsReceipt
    {
        if ($grn->status !== 'pending' || $grn->created_by !== $by->id) {
            throw ValidationException::withMessages(['receipt' => 'Only your delivery waiting for approval can be withdrawn.']);
        }
        $instance = $grn->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($grn, null, true);

        return $grn->refresh();
    }

    public function cancel(GoodsReceipt $grn): void
    {
        if (! in_array($grn->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['receipt' => 'Only a draft or returned delivery can be cancelled.']);
        }
        $grn->update(['status' => 'cancelled']);
    }

    /** Supplier invoice details for the 3-way match (order price, accepted quantity, invoice). */
    public function invoice(GoodsReceipt $grn, array $data): GoodsReceipt
    {
        if ($grn->status !== 'accepted') {
            throw ValidationException::withMessages(['receipt' => 'The supplier invoice is recorded on an accepted delivery.']);
        }
        if ($grn->paymentRequest && $grn->paymentRequest->vouchers()->whereIn('status', ['pending', 'approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['receipt' => 'A voucher is already prepared for this bill.']);
        }
        $grn->update(['supplier_invoice' => $data['supplier_invoice'], 'invoice_date' => $data['invoice_date'], 'invoice_amount' => round((float) $data['invoice_amount'], 2)]);

        return $grn->refresh();
    }

    public function matches(GoodsReceipt $grn): bool
    {
        if (! $grn->supplier_invoice || $grn->invoice_amount === null) {
            return false;
        }
        $tolerance = round($grn->total * ProcurementSetting::current()->match_tolerance_percent / 100, 2);

        return abs(round($grn->invoice_amount - $grn->total, 2)) <= $tolerance;
    }

    private function bill(GoodsReceipt $grn, array $byItem, int $clearing): PaymentRequest
    {
        $po = $grn->order;
        $supplier = $po->supplier;
        $bank = $supplier->bankAccounts->where('status', 'approved')->sortByDesc('is_default')->first();
        $department = $po->requisition?->department_id ?? \Modules\Budgeting\Models\BudgetItem::find(array_key_first($byItem))?->department_id;
        $budget = \Modules\Budgeting\Models\BudgetItem::find(array_key_first($byItem))?->budget_id;
        $r = PaymentRequest::create(['number' => Numbering::next(PaymentRequest::class, 'PR'), 'budget_id' => $budget, 'department_id' => $department,
            'title' => "Supplier bill {$grn->number} ({$po->number})", 'purpose' => "Goods received under {$po->number} from {$supplier->name}.", 'total' => $grn->total,
            'status' => 'approved', 'requested_by' => $grn->created_by, 'submitted_at' => now(), 'decided_at' => now(),
            'source_type' => $grn->getMorphClass(), 'source_id' => $grn->id, 'supplier_id' => $supplier->id]);
        foreach ($byItem as $itemId => $amount) {
            $r->lines()->create(['budget_item_id' => $itemId, 'account_id' => $clearing, 'description' => "{$grn->number} goods received", 'amount' => $amount]);
        }
        $r->payees()->create(['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
            'account_number' => $bank?->account_number, 'amount' => $grn->total]);

        return $r;
    }
}
