<?php

namespace Modules\Workflow\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowInstance extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = ['company_id', 'process', 'workflow_template_id', 'subject_type', 'subject_id', 'company_branch_id', 'department_id', 'status', 'current_step_order', 'initiated_by', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function steps(): HasMany
    {
        // Chronological (a sent-back request repeats steps after the earlier attempts).
        return $this->hasMany(WorkflowInstanceStep::class)->orderBy('id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function currentStep(): ?WorkflowInstanceStep
    {
        return $this->steps->firstWhere('status', WorkflowInstanceStep::PENDING);
    }
}
