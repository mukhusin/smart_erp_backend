<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Unit;

class PurchaseRequisitionLine extends Model
{
    protected $fillable = ['purchase_requisition_id', 'item_id', 'description', 'specification', 'unit_id', 'quantity', 'estimated_unit_cost', 'amount', 'budget_item_id', 'procurement_plan_line_id', 'ordered_quantity'];

    protected $casts = ['quantity' => 'float', 'estimated_unit_cost' => 'float', 'amount' => 'float', 'ordered_quantity' => 'float'];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
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

    public function planLine()
    {
        return $this->belongsTo(ProcurementPlanLine::class, 'procurement_plan_line_id');
    }

    public function remaining(): float
    {
        return max(0, round($this->quantity - $this->ordered_quantity, 4));
    }
}
