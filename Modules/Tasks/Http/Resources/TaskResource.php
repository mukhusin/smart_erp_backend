<?php

namespace Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Support\TaskAccess;

/** @mixin Task */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(TaskAccess::class);
        $user = $request->user();
        $mine = $user && $access->isAssignee($user, $this->resource);
        $manage = $user && $access->canManage($user, $this->resource);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'priority_label' => Task::PRIORITIES[$this->priority] ?? $this->priority,
            'status' => $this->status,
            'status_label' => Task::STATUS_LABELS[$this->status] ?? $this->status,
            'progress' => (int) $this->progress,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'recurrence' => $this->recurrence,
            'checklist' => $this->checklist ?? [],
            'review_comment' => $this->review_comment,
            'employee' => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->full_name, 'employee_no' => $this->employee->employee_no] : null,
            'department' => $this->department?->name,
            'activity' => $this->activity ? ['id' => $this->activity->id, 'title' => $this->activity->title] : null,
            'parent' => $this->parent ? ['id' => $this->parent->id, 'title' => $this->parent->title] : null,
            'created_by' => $this->creator?->display_name,
            'subtasks_count' => (int) ($this->subtasks_count ?? 0),
            'pending_update' => (bool) ($this->pending_updates_count ?? false),
            'can' => [
                'manage' => $manage,
                'start' => $mine && in_array($this->status, ['todo', 'returned'], true),
                'report' => $mine && in_array($this->status, ['todo', 'in_progress', 'returned'], true),
                'complete' => $mine && in_array($this->status, ['todo', 'in_progress', 'returned'], true),
                'review' => $manage && $this->status === 'review',
                'cancel' => $manage && in_array($this->status, Task::OPEN, true),
                'reopen' => $manage && in_array($this->status, ['done', 'cancelled'], true),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
