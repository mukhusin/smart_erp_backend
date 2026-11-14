<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** Official absence (travel, training, official duty away). Approved or ended days count as excused absence in attendance. */
class AbsenceRequest extends Model
{
    use BelongsToCompany;

    public const STATUS_LABELS = [
        'pending' => 'Waiting for approval', 'approved' => 'Approved', 'ended' => 'Ended', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled',
    ];

    /** Statuses whose days are excused. */
    public const EXCUSED = ['approved', 'ended'];

    protected $fillable = [
        'company_id', 'employee_id', 'last_updated_by',
        'from_date', 'to_date', 'from_time', 'to_time',
        'number_of_days', 'description', 'address', 'has_per_diem', 'status',
        'requested_by', 'decided_at', 'decision_comment',
    ];

    protected $casts = ['has_per_diem' => 'boolean', 'from_date' => 'date', 'to_date' => 'date', 'decided_at' => 'datetime'];

    public function employee()      { return $this->belongsTo(Employee::class); }
    public function lastUpdatedBy() { return $this->belongsTo(Employee::class, 'last_updated_by'); }
    public function requestedBy()   { return $this->belongsTo(User::class, 'requested_by'); }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    /** Approved absences show as ended once their last day has passed. */
    public function shownStatus(): string
    {
        return $this->status === 'approved' && $this->to_date?->lt(today()) ? 'ended' : $this->status;
    }
}
