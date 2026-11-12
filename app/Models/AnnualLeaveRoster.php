<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** An employee's planned annual leave on a department's annual leave roster. */
class AnnualLeaveRoster extends Model
{
    use BelongsToCompany;

    public const PLANNED = 'planned';

    public const REQUESTED = 'requested';

    public const TAKEN = 'taken';

    public const STATUS_LABELS = [self::PLANNED => 'Planned', self::REQUESTED => 'Requested', self::TAKEN => 'Approved'];

    protected $fillable = [
        'company_id', 'roster_id', 'employee_id', 'emp_leave_id', 'created_by', 'reminded_at', 'hod_id', 'hr_id',
        'department_id', 'start_date', 'end_date', 'days', 'leave_year',
        'report_date', 'is_reported', 'hod_remark', 'hod_is_approved',
        'hr_office_remark', 'hr_office_is_approved', 'description',
        'leave_phone', 'leave_address',
        'last_year_balance', 'current_balance', 'actual_balance',
        'status', 'is_active',
    ];

    protected $casts = [
        'hod_is_approved' => 'boolean', 'hr_office_is_approved' => 'boolean', 'is_reported' => 'boolean', 'is_active' => 'boolean',
        'start_date' => 'date', 'end_date' => 'date', 'reminded_at' => 'datetime', 'days' => 'integer',
    ];

    public function roster()
    {
        return $this->belongsTo(Roster::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leave()
    {
        return $this->belongsTo(EmpLeave::class, 'emp_leave_id');
    }

    public function hod()
    {
        return $this->belongsTo(Employee::class, 'hod_id');
    }

    public function hr()
    {
        return $this->belongsTo(Employee::class, 'hr_id');
    }
}
