<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeSheet extends Model
{
    protected $fillable = ['employee_id', 'payroll_sheet_detail_id', 'overtime_id', 'date_of_task_done', 'amount'];

    public function employee()           { return $this->belongsTo(Employee::class); }
    public function overtime()           { return $this->belongsTo(Overtime::class); }
    public function payrollSheetDetail() { return $this->belongsTo(PayrollSheetDetail::class); }
}
