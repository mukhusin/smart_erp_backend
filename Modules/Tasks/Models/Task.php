<?php

namespace Modules\Tasks\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'Waiting for review', 'done' => 'Done', 'returned' => 'Returned', 'cancelled' => 'Cancelled'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const OPEN = ['todo', 'in_progress', 'review', 'returned'];

    protected $fillable = [
        'company_id', 'task_activity_id', 'parent_id', 'title', 'description', 'priority', 'employee_id', 'department_id', 'created_by',
        'start_date', 'due_date', 'status', 'progress', 'checklist', 'recurrence', 'next_created', 'review_comment', 'completed_at',
    ];

    protected $casts = ['start_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime', 'checklist' => 'array', 'progress' => 'integer', 'next_created' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }

    public function parent()
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks()
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates()
    {
        return $this->hasMany(TaskUpdate::class)->latest('id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->oldest('id');
    }

    public function isOverdue(): bool
    {
        return $this->due_date && in_array($this->status, self::OPEN, true) && $this->due_date->lt(today());
    }

    public function assignedByOther(): bool
    {
        return $this->created_by && $this->employee?->user_id !== $this->created_by;
    }
}
