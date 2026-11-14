<?php

namespace Modules\Hr\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageAbsences;
use Modules\Workflow\Contracts\WorkflowProcess;

class AbsenceRequestProcess implements WorkflowProcess
{
    public const KEY = 'absence-request';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'leave';
    }

    public function name(): string
    {
        return 'Official absence';
    }

    public function description(): string
    {
        return 'Official absence away from work (travel, training, official duty), recorded by HR or a head of department. Approved days count as excused absence. Without this workflow, it is approved when entered.';
    }

    public function summary(Model $subject): array
    {
        $name = $subject->employee?->full_name ?? 'employee';
        $days = $subject->number_of_days == 1 ? '1 day' : "{$subject->number_of_days} days";

        return [
            'title' => "Official absence: {$name}",
            'lines' => array_values(array_filter([
                $subject->from_date->format('d M Y').' – '.$subject->to_date->format('d M Y')." ({$days})",
                $subject->address ? 'Where: '.$subject->address : null,
                'Purpose: '.$subject->description,
                $subject->has_per_diem ? 'With per diem' : null,
            ])),
            'link' => '/leave/absences',
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
        app(ManageAbsences::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageAbsences::class)->reject($subject, $comment);
    }
}
