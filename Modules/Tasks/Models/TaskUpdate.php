<?php

namespace Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class TaskUpdate extends Model
{
    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['task_id', 'user_id', 'note', 'progress', 'status', 'decision_comment', 'decided_at'];

    protected $casts = ['progress' => 'integer', 'decided_at' => 'datetime'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
