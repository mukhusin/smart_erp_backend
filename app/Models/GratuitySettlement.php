<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GratuitySettlement extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['paid' => 'Paid', 'forfeited' => 'Forfeited'];

    protected $fillable = ['company_id', 'employee_id', 'kind', 'date', 'amount', 'cash_bank_account_id', 'reference', 'notes', 'journal_entry_id', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'float'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function accruals()
    {
        return $this->hasMany(GratuityAccrual::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
