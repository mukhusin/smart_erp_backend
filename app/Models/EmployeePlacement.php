<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internship or residency period (HRMS internship_periods / resident_periods). */
class EmployeePlacement extends Model
{
    public const TYPES = ['internship' => 'Internship', 'residency' => 'Residency'];

    public const STATUSES = ['upcoming' => 'Upcoming', 'active' => 'Active', 'ended' => 'Ended', 'cancelled' => 'Cancelled'];

    /** Employee type each placement type belongs to. */
    public const EMP_TYPES = ['internship' => 'internship', 'residency' => 'resident'];

    protected $fillable = ['employee_id', 'type', 'year', 'period', 'start_date', 'end_date', 'status', 'comment', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'year' => 'integer', 'period' => 'integer'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
