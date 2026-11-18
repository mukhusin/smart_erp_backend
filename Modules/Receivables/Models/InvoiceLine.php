<?php

namespace Modules\Receivables\Models;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;
use Modules\Budgeting\Models\BudgetItem;

class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'description', 'account_id', 'department_id', 'budget_item_id', 'quantity', 'unit_price', 'amount', 'tax_code_id', 'tax_rate', 'tax_amount'];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float', 'tax_rate' => 'float', 'tax_amount' => 'float'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

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
