<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollSheetDetail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payroll_sheet_id', 'employee_id', 'contract_id', 'full_name', 'job_position',
        'basic_salary', 'actual_basic_salary', 'total_allowance', 'total_overtime',
        'allowance_overtime', 'leave_deduction', 'attendance_deduction', 'total_loan',
        'saccos_serving', 'nssf', 'nssf_employer', 'nhif', 'nhif_employer',
        'heslb', 'tughe', 'amount_taxable', 'paye', 'ef', 'nic', 'relief',
        'gross_salary', 'total_deduction', 'net_salary', 'allowances', 'wcf', 'sdl',
        'bank_id', 'bank_name', 'bank_branch', 'bank_code', 'bank_account', 'is_active',
    ];

    protected $casts = ['allowances' => 'array', 'is_active' => 'boolean'];

    public function payrollSheet() { return $this->belongsTo(PayrollSheet::class); }
    public function employee()     { return $this->belongsTo(Employee::class); }
    public function contract()     { return $this->belongsTo(EmployeeContract::class); }
}
