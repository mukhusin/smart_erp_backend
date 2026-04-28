<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowanceSheet extends Model
{
    protected $fillable = [
        'payroll_sheet_detail_id', 'emp_allowance_id', 'daily_allowance_id',
        'employee_id', 'allowance_name', 'comment', 'amount', 'description',
    ];

    public function payrollSheetDetail() { return $this->belongsTo(PayrollSheetDetail::class); }
    public function empAllowance()       { return $this->belongsTo(EmpAllowance::class); }
    public function dailyAllowance()     { return $this->belongsTo(DailyAllowance::class); }
    public function employee()           { return $this->belongsTo(Employee::class); }
}
