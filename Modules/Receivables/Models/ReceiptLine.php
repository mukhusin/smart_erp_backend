<?php

namespace Modules\Receivables\Models;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;
use Modules\Budgeting\Models\BudgetItem;

class ReceiptLine extends Model
{
    protected $fillable = ['receipt_id', 'description', 'account_id', 'department_id', 'budget_item_id', 'amount'];

    protected $casts = ['amount' => 'float'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
}
