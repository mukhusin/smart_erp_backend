<?php

namespace Modules\Hr\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageSickSheets;
use Modules\Workflow\Contracts\WorkflowProcess;

class ExcuseOfDutyProcess implements WorkflowProcess
{
    public const KEY = 'excuse-of-duty';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'hr';
    }

    public function name(): string
    {
        return 'Sick sheet (excuse of duty)';
    }

    public function description(): string
    {
        return 'Excuse of duty from a doctor, sent by the employee or by HR on their behalf. Approved days show on the duty roster and are excused in attendance. Without this workflow, it is approved when sent.';
    }

    public function summary(Model $subject): array
    {
        $name = $subject->employee?->full_name ?? 'employee';
        $days = $subject->number_of_days == 1 ? '1 day' : "{$subject->number_of_days} days";

        return [
            'title' => "Sick sheet: {$name}",
            'lines' => array_values(array_filter([
                $subject->start_date->format('d M Y').' – '.$subject->end_date->format('d M Y')." ({$days})",
                $subject->hospital_name || $subject->doctor_name ? 'From: '.collect([$subject->doctor_name, $subject->hospital_name])->filter()->implode(', ') : null,
                $subject->reason ? 'Note: '.$subject->reason : null,
                $subject->source === 'hr' ? 'Entered by HR' : null,
            ])),
            'link' => '/hr/sick-sheets',
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
        app(ManageSickSheets::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageSickSheets::class)->reject($subject, $comment);
    }
}
