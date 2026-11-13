<?php

namespace Modules\Hr\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManagePayItems;
use Modules\Workflow\Contracts\WorkflowProcess;

class AssignedAllowanceProcess implements WorkflowProcess
{
    public const KEY = 'assigned-allowance';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'allowance';
    }

    public function name(): string
    {
        return 'Assigned allowance';
    }

    public function description(): string
    {
        return 'A fixed monthly allowance for one employee. Without this workflow, it applies when entered.';
    }

    public function summary(Model $subject): array
    {
        $m = fn ($v) => number_format((float) $v, 2);
        $name = $subject->employee?->full_name ?? 'employee';

        return [
            'title' => "{$subject->category} for {$name}",
            'lines' => array_values(array_filter([
                $m($subject->amount).' a month from '.$subject->start_date->format('d M Y').($subject->end_date ? ' to '.$subject->end_date->format('d M Y') : ''),
                $subject->description ? 'Note: '.$subject->description : null,
            ])),
            'link' => null,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->employee?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManagePayItems::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePayItems::class)->reject($subject, $comment);
    }
}
