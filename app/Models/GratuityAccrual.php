<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GratuityAccrual extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'payroll_sheet_id', 'employee_contract_id', 'year', 'month', 'base_amount', 'rate_percent', 'amount', 'gratuity_settlement_id'];

    protected $casts = ['base_amount' => 'float', 'rate_percent' => 'float', 'amount' => 'float'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract()
    {
        return $this->belongsTo(EmployeeContract::class, 'employee_contract_id');
    }

    public function settlement()
    {
        return $this->belongsTo(GratuitySettlement::class, 'gratuity_settlement_id');
    }
}
