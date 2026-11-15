<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One employee's line of an uploaded salary file; `data` keeps every column of the file. */
class UploadedPayrollRow extends Model
{
    protected $fillable = ['uploaded_payroll_id', 'employee_id', 'employee_no', 'gross_salary', 'net_pay', 'data'];

    protected $casts = ['data' => 'array', 'gross_salary' => 'float', 'net_pay' => 'float'];

    public function upload()
    {
        return $this->belongsTo(UploadedPayroll::class, 'uploaded_payroll_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }
}
