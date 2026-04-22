<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceClaim extends Model
{
    protected $fillable = [
        'attendance_transa_id', 'employee_id', 'claim_reason_id', 'approved_by',
        'claim_date', 'approved_at', 'set_lost_hr_zero', 'hr_accepted',
        'lost_hour', 'lost_amount', 'percent', 'remark', 'description', 'comment', 'claim_status',
    ];

    protected $casts = ['set_lost_hr_zero' => 'boolean'];

    public function transaction() { return $this->belongsTo(AttendanceTransa::class, 'attendance_transa_id'); }
    public function employee()    { return $this->belongsTo(Employee::class); }
    public function reason()      { return $this->belongsTo(AttendanceClaimReason::class, 'claim_reason_id'); }
    public function approvedBy()  { return $this->belongsTo(Employee::class, 'approved_by'); }
}
