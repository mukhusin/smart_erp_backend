<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanHistory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'payroll_sheet_id', 'payroll_sheet_detail_id', 'employee_loan_id',
        'loan_provider', 'repayment_date', 'repaid_amount', 'instalment',
        'principle_amount', 'outstanding_loan',
    ];

    public function employee()           { return $this->belongsTo(Employee::class); }
    public function payrollSheet()       { return $this->belongsTo(PayrollSheet::class); }
    public function payrollSheetDetail() { return $this->belongsTo(PayrollSheetDetail::class); }
    public function employeeLoan()       { return $this->belongsTo(EmployeeLoan::class); }
}
