<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class ContractLine extends Model
{

    public $timestamps = false;

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function budgetItem()
    {
        return $this->belongsTo(\Modules\Budgeting\Models\BudgetItem::class);
    }

    public function remaining(): float
    {
        return round($this->quantity - $this->ordered_quantity, 4);
    }

    protected $fillable = ['contract_id', 'purchase_requisition_line_id', 'item_id', 'description', 'unit_id', 'quantity', 'unit_price', 'amount', 'budget_item_id', 'ordered_quantity'];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float', 'ordered_quantity' => 'float'];
}
