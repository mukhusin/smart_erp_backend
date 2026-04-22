<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnualLeaveRoster extends Model
{
    protected $fillable = [
        'company_id', 'roster_id', 'employee_id', 'hod_id', 'hr_id',
        'department_id', 'start_date', 'end_date', 'days', 'leave_year',
        'report_date', 'is_reported', 'hod_remark', 'hod_is_approved',
        'hr_office_remark', 'hr_office_is_approved', 'description',
        'leave_phone', 'leave_address',
        'last_year_balance', 'current_balance', 'actual_balance',
        'status', 'is_active',
    ];

    protected $casts = [
        'hod_is_approved' => 'boolean', 'hr_office_is_approved' => 'boolean',
        'is_reported' => 'boolean', 'is_active' => 'boolean',
    ];

    public function roster()   { return $this->belongsTo(Roster::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function hod()      { return $this->belongsTo(Employee::class, 'hod_id'); }
    public function hr()       { return $this->belongsTo(Employee::class, 'hr_id'); }
    public function company()  { return $this->belongsTo(Company::class); }
}
