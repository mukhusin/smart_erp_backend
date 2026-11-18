<?php

namespace Modules\Procurement\Models;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Unit;

class ProcurementPlanLine extends Model
{
    protected $fillable = ['procurement_plan_id', 'department_id', 'budget_item_id', 'item_id', 'description', 'category', 'procurement_method_id', 'unit_id', 'quantity',
        'estimated_unit_cost', 'amount', 'quarter', 'notes'];

    protected $casts = ['quantity' => 'float', 'estimated_unit_cost' => 'float', 'amount' => 'float', 'quarter' => 'integer'];

    public function plan()
    {
        return $this->belongsTo(ProcurementPlan::class, 'procurement_plan_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function budgetItem()
    {
        return $this->belongsTo(BudgetItem::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function method()
    {
        return $this->belongsTo(ProcurementMethod::class, 'procurement_method_id');
    }

    public function requisitionLines()
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }
}
