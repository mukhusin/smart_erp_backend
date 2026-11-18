<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;

class ImprestRetirementLine extends Model
{
    protected $fillable = ['imprest_retirement_id', 'budget_item_id', 'description', 'amount', 'input_vat', 'vat_invoice_no'];

    protected $casts = ['amount' => 'float', 'input_vat' => 'float'];

    public function retirement()
    {
        return $this->belongsTo(ImprestRetirement::class, 'imprest_retirement_id');
    }

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
