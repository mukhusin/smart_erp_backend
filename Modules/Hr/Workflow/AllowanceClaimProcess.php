<?php

namespace Modules\Hr\Workflow;

use App\Models\DailyAllowance;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageAllowances;
use Modules\Workflow\Contracts\WorkflowProcess;

class AllowanceClaimProcess implements WorkflowProcess
{
    public const KEY = 'allowance-claim';

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
        return 'Allowance claim';
    }

    public function description(): string
    {
        return 'An allowance entered with a count (e.g. theatre cases, call-outs). Allowances worked out from the roster are not approved one by one.';
    }

    /** @param DailyAllowance $subject */
    public function summary(Model $subject): array
    {
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

        return [
            'title' => $subject->category.' for '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                $subject->start_date->format('d M Y').': '.$num($subject->items).' × '.number_format($subject->amount_per_item, 2).' = '.number_format($subject->amount, 2),
                $subject->employee?->department ? 'Department: '.$subject->employee->department->name : null,
                $subject->description ? 'Note: '.$subject->description : null,
                $subject->requester ? 'Entered by '.$subject->requester->display_name : null,
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
        app(ManageAllowances::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageAllowances::class)->reject($subject, $comment);
    }
}
