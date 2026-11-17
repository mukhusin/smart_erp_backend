<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;

class ImprestRetirementLine extends Model
{
    protected $fillable = ['imprest_retirement_id', 'budget_item_id', 'description', 'amount'];

    protected $casts = ['amount' => 'float'];

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
