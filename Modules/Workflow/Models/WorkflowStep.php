<?php

namespace Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class WorkflowStep extends Model
{
    public const APPROVER_ROLE = 'role';
    public const APPROVER_USER = 'user';
    public const APPROVER_DEPARTMENT_HEAD = 'department_head';

    protected $fillable = ['workflow_template_id', 'step_order', 'name', 'approver_type', 'role_id', 'user_id', 'same_branch', 'allow_send_back', 'approve_label', 'reject_label'];

    protected $casts = ['same_branch' => 'boolean', 'allow_send_back' => 'boolean'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
