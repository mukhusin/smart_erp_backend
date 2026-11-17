<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Unit;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'purchase_requisition_line_id', 'contract_line_id', 'item_id', 'description', 'unit_id', 'quantity', 'unit_price', 'amount', 'budget_item_id', 'received_quantity'];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float', 'received_quantity' => 'float'];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function budgetItem()
    {
        return $this->belongsTo(BudgetItem::class);
    }

    public function requisitionLine()
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    public function due(): float
    {
        return max(0, round($this->quantity - $this->received_quantity, 4));
    }
}
