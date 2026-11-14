<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExcuseOfDuty extends Model
{
    use BelongsToCompany;

    protected $table = 'excuse_of_duties';

    protected $fillable = ['company_id', 'employee_id', 'start_date', 'end_date', 'number_of_days', 'hospital_name', 'doctor_name', 'patient_address', 'reason', 'status', 'created_by',
        'source', 'requested_by', 'decided_at', 'decision_comment', 'file_path', 'file_name'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'active' => 'Approved', 'ended' => 'Ended', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    /** Statuses whose days are excused (sick off). */
    public const EXCUSED = ['active', 'ended'];

    public function workflow()
    {
        return $this->morphOne(\Modules\Workflow\Models\WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Approved sheets show as ended once their last day has passed. */
    public function shownStatus(): string
    {
        return $this->status === 'active' && $this->end_date?->lt(today()) ? 'ended' : $this->status;
    }

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'decided_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
