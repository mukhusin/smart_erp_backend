<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\BudgetItem;

class PaymentRequestLine extends Model
{
    protected $fillable = ['payment_request_id', 'budget_item_id', 'account_id', 'description', 'amount'];

    protected $casts = ['amount' => 'float'];

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
