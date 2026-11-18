<?php

namespace Modules\Workflow\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/** A background action an administrator attaches to a workflow event (notify, escalate, send to another system). */
class WorkflowAction extends Model
{
    use Auditable, BelongsToCompany;

    public const EVENTS = ['started' => 'Request sent', 'step_approved' => 'A step approved', 'approved' => 'Finally approved', 'rejected' => 'Not approved',
        'sent_back' => 'Sent back', 'cancelled' => 'Withdrawn', 'overdue' => 'Waiting too long at a step'];

    public const ACTIONS = ['notify' => 'Notify', 'escalate' => 'Escalate to another approver', 'webhook' => 'Send to another system'];

    public const TARGETS = ['requester' => 'The requester', 'role' => 'Everyone with a role', 'user' => 'A person', 'department_head' => 'Head of the department', 'approvers' => 'Who must act now'];

    public const CHANNELS = ['app' => 'In the app', 'email' => 'Email', 'sms' => 'SMS'];

    protected $fillable = ['company_id', 'workflow_template_id', 'event', 'step_order', 'min_amount', 'action', 'target', 'role_id', 'user_id', 'channels', 'after_days', 'message', 'url', 'secret', 'is_active'];

    protected $casts = ['channels' => 'array', 'min_amount' => 'float', 'step_order' => 'integer', 'after_days' => 'integer', 'secret' => 'encrypted', 'is_active' => 'boolean'];

    protected $hidden = ['secret'];

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logs()
    {
        return $this->hasMany(WorkflowActionLog::class)->latest('id');
    }
}
