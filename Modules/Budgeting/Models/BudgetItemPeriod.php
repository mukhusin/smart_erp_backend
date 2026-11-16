<?php

namespace Modules\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetItemPeriod extends Model
{
    public $timestamps = false;

    protected $fillable = ['budget_item_id', 'period', 'amount'];

    protected $casts = ['period' => 'integer', 'amount' => 'float'];
}
