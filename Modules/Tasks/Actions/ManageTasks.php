<?php

namespace Modules\Tasks\Actions;

use App\Models\Employee;
use App\Models\HrSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskActivity;
use Modules\Tasks\Models\TaskComment;
use Modules\Tasks\Models\TaskUpdate;
use Modules\Tasks\Support\TaskAccess;
use Modules\Tasks\Workflow\TaskProgressProcess;
use Modules\Workflow\Services\WorkflowEngine;

class ManageTasks
{
    public function __construct(private readonly TaskAccess $access, private readonly WorkflowEngine $workflow) {}

    public function create(array $data, User $by): Collection
    {
        $ids = array_values(array_unique($data['employee_ids'] ?? [])) ?: [$by->employee?->id];
        $employees = Employee::withoutGlobalScopes()->whereIn('id', array_filter($ids))->get();
        if ($employees->count() !== count(array_filter($ids))) {
            throw ValidationException::withMessages(['employee_ids' => 'Choose the employees to give the task to.']);
        }
        foreach ($employees as $employee) {
            if (! $this->access->canAssignTo($by, $employee)) {
                throw ValidationException::withMessages(['employee_ids' => "You cannot give tasks to {$employee->full_name}."]);
            }
        }
        if (! empty($data['task_activity_id']) && ! TaskActivity::whereKey($data['task_activity_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['task_activity_id' => 'This activity is not active (its department plan may not be approved yet).']);
        }
        if (! empty($data['parent_id'])) {
            $parent = Task::findOrFail($data['parent_id']);
            if (! $this->access->canView($by, $parent)) {
                throw ValidationException::withMessages(['parent_id' => 'You cannot add sub-tasks to this task.']);
            }
            $data['task_activity_id'] ??= $parent->task_activity_id;
        }

        return DB::transaction(fn () => $employees->map(function (Employee $employee) use ($data, $by) {
            $task = Task::create($this->fields($data) + [
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'created_by' => $by->id,
                'status' => 'todo',
                'progress' => 0,
            ]);
            if ($employee->user_id && $employee->user_id !== $by->id) {
                $employee->user?->notify(new Notice('New task', "{$by->display_name} gave you a task: {$task->title}".($task->due_date ? ', due '.$task->due_date->format('d M Y') : '').'.', "/tasks/{$task->id}", 'list-check'));
            }

            return $task;
        }));
    }

    public function update(Task $task, array $data, User $by): Task
    {
        $this->ensureManage($task, $by);
        $this->ensureOpen($task);
        $task->update($this->fields($data));

        return $task;
    }

    public function start(Task $task, User $by): Task
    {
        $this->ensureAssignee($task, $by);
        if (! in_array($task->status, ['todo', 'returned'], true)) {
            throw ValidationException::withMessages(['task' => 'Only a task to do or returned can be started.']);
        }
        $task->update(['status' => 'in_progress', 'start_date' => $task->start_date ?? today()]);

        return $task;
    }

    public function complete(Task $task, User $by, ?string $note = null): Task
    {
        $this->ensureAssignee($task, $by);
        if (! in_array($task->status, ['todo', 'in_progress', 'returned'], true)) {
            throw ValidationException::withMessages(['task' => 'This task cannot be finished now.']);
        }
        $this->cancelPendingUpdate($task, $by);
        if ($task->assignedByOther()) {
            $task->update(['status' => 'review', 'review_comment' => $note]);
            $task->creator?->notify(new Notice('Task finished', "{$task->employee?->full_name} finished: {$task->title}. Review it.", "/tasks/{$task->id}", 'list-check'));
        } else {
            $task->update(['status' => 'done', 'progress' => 100, 'completed_at' => now()]);
        }

        return $task;
    }

    public function review(Task $task, User $by, bool $accept, ?string $comment = null): Task
    {
        $this->ensureManage($task, $by);
        if ($task->status !== 'review') {
            throw ValidationException::withMessages(['task' => 'This task is not waiting for review.']);
        }
        if (! $accept && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'Say what should be done again.']);
        }
        $task->update($accept
            ? ['status' => 'done', 'progress' => 100, 'completed_at' => now(), 'review_comment' => $comment]
            : ['status' => 'returned', 'review_comment' => $comment]);
        $task->employee?->user?->notify($accept
            ? new Notice('Task accepted', "{$task->title} is accepted as done.", "/tasks/{$task->id}", 'check2-circle')
            : new Notice('Task returned', "{$task->title} was returned: {$comment}", "/tasks/{$task->id}", 'arrow-counterclockwise'));

        return $task;
    }

    public function cancel(Task $task, User $by): Task
    {
        $this->ensureManage($task, $by);
        $this->ensureOpen($task);
        $this->cancelPendingUpdate($task, $by);
        $task->update(['status' => 'cancelled']);
        $task->subtasks()->whereIn('status', Task::OPEN)->get()->each(fn (Task $t) => $t->update(['status' => 'cancelled']));

        return $task;
    }

    public function reopen(Task $task, User $by): Task
    {
        $this->ensureManage($task, $by);
        if (! in_array($task->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task' => 'Only a finished or cancelled task can be reopened.']);
        }
        $task->update(['status' => $task->progress > 0 ? 'in_progress' : 'todo', 'completed_at' => null, 'progress' => min($task->progress, 99)]);

        return $task;
    }

    public function addUpdate(Task $task, User $by, string $note, int $progress): TaskUpdate
    {
        $this->ensureAssignee($task, $by);
        if (! in_array($task->status, ['todo', 'in_progress', 'returned'], true)) {
            throw ValidationException::withMessages(['task' => 'Progress can only be reported on a task in progress.']);
        }
        if ($task->updates()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['progress' => 'Your last update is still waiting for approval.']);
        }
        if ($progress <= $task->progress || $progress > 100) {
            throw ValidationException::withMessages(['progress' => "Progress must be more than the current {$task->progress}% and at most 100%."]);
        }
        $step = (int) (HrSetting::current()->task_max_step ?? 30);
        if ($step > 0 && $progress - $task->progress > $step) {
            throw ValidationException::withMessages(['progress' => "Progress can go up by at most {$step}% in one update."]);
        }

        return DB::transaction(function () use ($task, $by, $note, $progress) {
            $update = $task->updates()->create(['user_id' => $by->id, 'note' => $note, 'progress' => $progress, 'status' => 'pending']);
            $task->status === 'todo' && $task->update(['status' => 'in_progress', 'start_date' => $task->start_date ?? today()]);
            if (! $this->workflow->start(TaskProgressProcess::KEY, $update, $by)) {
                $this->applyUpdate($update);
            }

            return $update->refresh();
        });
    }

    public function applyUpdate(TaskUpdate $update): void
    {
        $update->update(['status' => 'approved', 'decided_at' => now()]);
        $task = $update->task;
        if ($update->progress > $task->progress) {
            $task->update(['progress' => $update->progress]);
        }
    }

    public function rejectUpdate(TaskUpdate $update, ?string $comment, bool $withdrawn = false): void
    {
        $update->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $update->task?->employee?->user?->notify(new Notice('Progress not approved', "Your update on {$update->task->title} was not approved.".($comment ? " Reason: {$comment}" : ''), "/tasks/{$update->task_id}", 'list-check'));
        }
    }

    public function withdrawUpdate(TaskUpdate $update, User $by): void
    {
        if ($update->status !== 'pending' || $update->user_id !== $by->id) {
            throw ValidationException::withMessages(['update' => 'Only your update waiting for approval can be withdrawn.']);
        }
        $this->cancelUpdate($update, $by);
    }

    public function comment(Task $task, User $by, string $body): TaskComment
    {
        $comment = $task->comments()->create(['user_id' => $by->id, 'body' => $body]);
        collect([$task->employee?->user, $task->creator])->filter()->unique('id')->reject(fn ($u) => $u->id === $by->id)
            ->each(fn ($u) => $u->notify(new Notice('Comment on task', "{$by->display_name} on {$task->title}: ".str($body)->limit(120), "/tasks/{$task->id}", 'chat-left-text')));

        return $comment;
    }

    public function checklist(Task $task, User $by, array $items): Task
    {
        if (! $this->access->isAssignee($by, $task) && ! $this->access->canManage($by, $task)) {
            abort(403);
        }
        $this->ensureOpen($task);
        $task->update(['checklist' => array_values(array_map(fn ($i) => ['text' => (string) $i['text'], 'done' => (bool) ($i['done'] ?? false)], $items))]);

        return $task;
    }

    public function repeat(Task $task): ?Task
    {
        if (! $task->recurrence || $task->next_created || ! $task->due_date) {
            return null;
        }
        $shift = fn ($date) => $date ? ($task->recurrence === 'weekly' ? $date->copy()->addWeek() : $date->copy()->addMonthNoOverflow()) : null;

        return DB::transaction(function () use ($task, $shift) {
            $task->update(['next_created' => true]);

            return Task::create([
                'company_id' => $task->company_id, 'task_activity_id' => $task->task_activity_id, 'title' => $task->title, 'description' => $task->description,
                'priority' => $task->priority, 'employee_id' => $task->employee_id, 'department_id' => $task->department_id, 'created_by' => $task->created_by,
                'start_date' => $shift($task->start_date), 'due_date' => $shift($task->due_date), 'status' => 'todo', 'progress' => 0, 'recurrence' => $task->recurrence,
                'checklist' => $task->checklist ? array_map(fn ($i) => ['text' => $i['text'], 'done' => false], $task->checklist) : null,
            ]);
        });
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['title', 'description', 'priority', 'task_activity_id', 'parent_id', 'start_date', 'due_date', 'checklist', 'recurrence']));
    }

    private function cancelPendingUpdate(Task $task, User $by): void
    {
        $task->updates()->where('status', 'pending')->get()->each(fn (TaskUpdate $u) => $this->cancelUpdate($u, $by));
    }

    private function cancelUpdate(TaskUpdate $update, User $by): void
    {
        if ($instance = $update->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, $instance->initiator ?? $by);
        }
        $update->refresh()->status === 'pending' && $update->update(['status' => 'cancelled', 'decided_at' => now()]);
    }

    private function ensureAssignee(Task $task, User $by): void
    {
        if (! $this->access->isAssignee($by, $task)) {
            throw ValidationException::withMessages(['task' => 'Only the employee the task is for can do this.']);
        }
    }

    private function ensureManage(Task $task, User $by): void
    {
        if (! $this->access->canManage($by, $task)) {
            abort(403, 'You cannot change this task.');
        }
    }

    private function ensureOpen(Task $task): void
    {
        if (! in_array($task->status, Task::OPEN, true)) {
            throw ValidationException::withMessages(['task' => 'This task is already '.strtolower(Task::STATUS_LABELS[$task->status]).'.']);
        }
    }
}
