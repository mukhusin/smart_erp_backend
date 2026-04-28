<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollComment extends Model
{
    protected $fillable = ['payroll_sheet_id', 'employee_id', 'comment', 'remarks'];

    public function payrollSheet() { return $this->belongsTo(PayrollSheet::class); }
    public function employee()     { return $this->belongsTo(Employee::class); }
}
