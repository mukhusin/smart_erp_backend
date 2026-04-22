<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollDateSetting extends Model
{
    protected $fillable = [
        'company_id', 'from_day', 'from_month', 'to_day', 'to_month',
        'wcf', 'sdl', 'heslb', 'paye', 'income_tax',
        'nhif_employee', 'nhif_employer', 'nssf_employer', 'nssf_employee', 'emergency_fund',
        'use_overtime', 'use_loan', 'use_allowance', 'use_attendance',
        'use_leave_deduction', 'use_deduction', 'is_active', 'updated_by',
    ];

    protected $casts = [
        'use_overtime' => 'boolean', 'use_loan' => 'boolean', 'use_allowance' => 'boolean',
        'use_attendance' => 'boolean', 'use_leave_deduction' => 'boolean',
        'use_deduction' => 'boolean', 'is_active' => 'boolean',
    ];

    public function company()   { return $this->belongsTo(Company::class); }
    public function updatedBy() { return $this->belongsTo(Employee::class, 'updated_by'); }
}
