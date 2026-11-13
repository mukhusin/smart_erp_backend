<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class AttendanceClaim extends Model
{
    protected $fillable = [
        'attendance_transa_id', 'employee_id', 'claim_reason_id', 'approved_by',
        'claim_date', 'approved_at', 'set_lost_hr_zero', 'hr_accepted',
        'lost_hour', 'lost_amount', 'percent', 'remark', 'description', 'comment', 'claim_status', 'requested_by',
    ];

    protected $casts = ['set_lost_hr_zero' => 'boolean', 'claim_date' => 'date', 'approved_at' => 'datetime', 'lost_hour' => 'float', 'hr_accepted' => 'float', 'lost_amount' => 'float'];

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function transaction()
    {
        return $this->belongsTo(AttendanceTransa::class, 'attendance_transa_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reason()
    {
        return $this->belongsTo(AttendanceClaimReason::class, 'claim_reason_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}
