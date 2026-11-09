<?php

namespace Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class WorkflowInstanceStep extends Model
{
    public const WAITING = 'waiting';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const SENT_BACK = 'sent_back';

    protected $fillable = ['workflow_instance_id', 'step_order', 'name', 'approver_type', 'role_id', 'user_id', 'same_branch', 'allow_send_back', 'approve_label', 'reject_label', 'status', 'acted_by', 'acted_at', 'comment'];

    protected $casts = ['same_branch' => 'boolean', 'allow_send_back' => 'boolean', 'acted_at' => 'datetime'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
