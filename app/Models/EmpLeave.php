<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmpLeave extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    /** Statuses whose days are taken from the balance. */
    public const TAKEN = ['approved', 'on_leave', 'reported'];

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'employee_dependant_id',
        'hod_id', 'hr_id', 'department_id',
        'start_date', 'end_date', 'requested_start_date', 'requested_end_date',
        'days', 'days_requested', 'leave_year', 'description',
        'hod_remark', 'hod_is_approved', 'hr_office_remark', 'hr_office_is_approved',
        'report_date', 'is_reported', 'leave_phone', 'leave_address',
        'days_passed', 'balance', 'deducted', 'amount', 'is_active', 'status',
    ];

    protected $casts = [
        'hod_is_approved' => 'boolean', 'hr_office_is_approved' => 'boolean',
        'is_reported' => 'boolean', 'deducted' => 'boolean', 'is_active' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function hod()
    {
        return $this->belongsTo(Employee::class, 'hod_id');
    }

    public function hrOfficer()
    {
        return $this->belongsTo(Employee::class, 'hr_id');
    }

    public function dependant()
    {
        return $this->belongsTo(EmployeeDependant::class, 'employee_dependant_id');
    }
}
