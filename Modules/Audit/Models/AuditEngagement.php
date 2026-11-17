<?php

namespace Modules\Audit\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class AuditEngagement extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'active' => 'Access given', 'rejected' => 'Not approved', 'ended' => 'Access ended', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'firm', 'auditor_name', 'email', 'period_from', 'period_to', 'access_until', 'payslips', 'scope_note', 'status', 'user_id', 'created_by',
        'decision_comment', 'decided_at'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'access_until' => 'date', 'payslips' => 'boolean', 'decided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
