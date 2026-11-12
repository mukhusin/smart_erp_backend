<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** A leave request: waiting for approval, approved, on leave, reported back, rejected or cancelled. */
class EmpLeave extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const ON_LEAVE = 'on_leave';

    public const REPORTED = 'reported';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Statuses whose days are taken from the balance. */
    public const TAKEN = [self::APPROVED, self::ON_LEAVE, self::REPORTED];

    /** Statuses that block another leave on the same days. */
    public const OPEN = [self::PENDING, self::APPROVED, self::ON_LEAVE];

    public const STATUS_LABELS = [
        self::PENDING => 'Waiting for approval', self::APPROVED => 'Approved', self::ON_LEAVE => 'On leave', self::REPORTED => 'Reported back',
        self::REJECTED => 'Not approved', self::CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'employee_dependant_id',
        'hod_id', 'hr_id', 'department_id',
        'start_date', 'end_date', 'requested_start_date', 'requested_end_date',
        'days', 'days_requested', 'leave_year', 'description', 'details',
        'hod_remark', 'hod_is_approved', 'hr_office_remark', 'hr_office_is_approved',
        'report_date', 'is_reported', 'leave_phone', 'leave_address',
        'days_passed', 'balance', 'deducted', 'amount', 'is_active', 'status',
        'source', 'requested_by', 'decided_at', 'decision_comment', 'agreed_at',
    ];

    protected $casts = [
        'hod_is_approved' => 'boolean', 'hr_office_is_approved' => 'boolean', 'is_reported' => 'boolean', 'deducted' => 'boolean', 'is_active' => 'boolean',
        'start_date' => 'date', 'end_date' => 'date', 'requested_start_date' => 'date', 'requested_end_date' => 'date', 'report_date' => 'date',
        'days' => 'float', 'days_requested' => 'float', 'details' => 'array', 'decided_at' => 'datetime', 'agreed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
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

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function files()
    {
        return $this->hasMany(EmpLeaveFile::class);
    }

    public function changes()
    {
        return $this->hasMany(EmpLeaveChange::class)->latest('id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
