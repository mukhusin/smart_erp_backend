<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** An overtime claim: time worked outside the planned shift, paid by the overtime rate once approved. */
class Overtime extends Model
{
    use BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id', 'employee_id', 'department_id', 'section_id', 'overtime_rate_id',
        'hod_approved', 'hr_approved', 'is_hod_approved', 'is_hr_approved',
        'is_paid', 'hours', 'minutes', 'overtime_minutes', 'rate_unit', 'rate_amount', 'amount_per_minute', 'amount',
        'task_title', 'date_of_task_done', 'from_datetime', 'to_datetime', 'planned',
        'type', 'description', 'hod_comment', 'hr_comment', 'status', 'is_active',
        'source', 'requested_by', 'decided_at', 'decision_comment',
    ];

    protected $casts = [
        'is_hod_approved' => 'boolean', 'is_hr_approved' => 'boolean', 'is_paid' => 'boolean', 'is_active' => 'boolean',
        'date_of_task_done' => 'date', 'from_datetime' => 'datetime', 'to_datetime' => 'datetime', 'planned' => 'array',
        'amount' => 'float', 'rate_amount' => 'float', 'amount_per_minute' => 'float', 'decided_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function section()
    {
        return $this->belongsTo(Department::class, 'section_id');
    }

    public function rate()
    {
        return $this->belongsTo(OvertimeRate::class, 'overtime_rate_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function hodApprover()
    {
        return $this->belongsTo(Employee::class, 'hod_approved');
    }

    public function hrApprover()
    {
        return $this->belongsTo(Employee::class, 'hr_approved');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
