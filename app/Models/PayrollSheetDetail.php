<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One employee's pay on a payroll sheet, with the lines that make it up (for the payslip). */
class PayrollSheetDetail extends Model
{
    protected $fillable = [
        'payroll_sheet_id', 'employee_id', 'contract_id', 'full_name', 'job_position', 'basic_salary', 'actual_basic_salary',
        'total_allowance', 'total_overtime', 'allowance_overtime', 'leave_deduction', 'attendance_deduction', 'total_loan', 'saccos_serving',
        'nssf', 'nssf_employer', 'nhif', 'nhif_employer', 'heslb', 'tughe', 'amount_taxable', 'paye', 'ef', 'nic', 'relief',
        'gross_salary', 'total_deduction', 'net_salary', 'allowances', 'lines', 'days_paid', 'unpaid_days', 'advance', 'emp_category',
        'wcf', 'sdl', 'bank_id', 'bank_name', 'bank_branch', 'bank_code', 'bank_account', 'is_active',
    ];

    protected $casts = [
        'allowances' => 'array', 'lines' => 'array', 'is_active' => 'boolean',
        'basic_salary' => 'float', 'actual_basic_salary' => 'float', 'total_allowance' => 'float', 'total_overtime' => 'float', 'leave_deduction' => 'float',
        'total_loan' => 'float', 'saccos_serving' => 'float', 'nssf' => 'float', 'nssf_employer' => 'float', 'nhif' => 'float', 'nhif_employer' => 'float',
        'heslb' => 'float', 'tughe' => 'float', 'amount_taxable' => 'float', 'paye' => 'float', 'ef' => 'float', 'nic' => 'float', 'relief' => 'float',
        'gross_salary' => 'float', 'total_deduction' => 'float', 'net_salary' => 'float', 'wcf' => 'float', 'sdl' => 'float', 'advance' => 'float',
        'days_paid' => 'float', 'unpaid_days' => 'float',
    ];

    public function payrollSheet()
    {
        return $this->belongsTo(PayrollSheet::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract()
    {
        return $this->belongsTo(EmployeeContract::class);
    }
}
