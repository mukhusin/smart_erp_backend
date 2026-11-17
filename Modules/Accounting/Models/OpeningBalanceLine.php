<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use App\Models\Department;

class OpeningBalanceLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'opening_balance_id', 'kind', 'reference', 'account_id', 'debit', 'credit', 'department_id', 'fund_source_id', 'task_activity_id',
        'supplier_id', 'customer_id', 'party', 'document_date', 'due_date', 'description', 'target_type', 'target_id'];

    protected $casts = ['debit' => 'float', 'credit' => 'float', 'document_date' => 'date', 'due_date' => 'date'];

    public function batch()
    {
        return $this->belongsTo(OpeningBalance::class, 'opening_balance_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function fundSource()
    {
        return $this->belongsTo(FundSource::class);
    }

    public function target()
    {
        return $this->morphTo();
    }

    public function amount(): float
    {
        return round(abs($this->debit - $this->credit), 2);
    }
}
