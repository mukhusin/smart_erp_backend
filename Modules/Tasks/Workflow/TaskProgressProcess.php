<?php

namespace Modules\Tasks\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Actions\ManageTasks;
use Modules\Tasks\Models\TaskUpdate;
use Modules\Workflow\Contracts\WorkflowProcess;

class TaskProgressProcess implements WorkflowProcess
{
    public const KEY = 'task-progress';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'tasks';
    }

    public function name(): string
    {
        return 'Task progress';
    }

    public function description(): string
    {
        return 'Progress an employee reports on a task. It counts once approved.';
    }

    /** @param TaskUpdate $subject */
    public function summary(Model $subject): array
    {
        $task = $subject->task;

        return [
            'title' => 'Task progress: '.$task?->title,
            'lines' => array_values(array_filter([
                ($task?->employee?->full_name ?? 'Employee').': '.($task?->progress ?? 0).'% → '.$subject->progress.'%',
                $task?->due_date ? 'Due '.$task->due_date->format('d M Y') : null,
                $subject->note,
            ])),
            'link' => "/tasks/{$subject->task_id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->task?->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->task?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->task?->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTasks::class)->applyUpdate($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTasks::class)->rejectUpdate($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
