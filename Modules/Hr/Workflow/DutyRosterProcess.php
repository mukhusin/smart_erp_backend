<?php

namespace Modules\Hr\Workflow;

use App\Models\Roster;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageRosters;
use Modules\Workflow\Contracts\WorkflowProcess;

class DutyRosterProcess implements WorkflowProcess
{
    public const KEY = 'duty-roster';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'roster';
    }

    public function name(): string
    {
        return 'Duty roster';
    }

    public function description(): string
    {
        return 'Publishing a department or section duty roster. Without this workflow, rosters are published when submitted.';
    }

    /** @param Roster $subject */
    public function summary(Model $subject): array
    {
        $employees = $subject->duties()->distinct()->count('employee_id');
        $hours = round((float) $subject->duties()->sum('work_hour'), 1);

        return [
            'title' => 'Duty roster: '.$subject->title,
            'lines' => array_values(array_filter([
                ($subject->section ? $subject->section->name.', ' : '').($subject->department?->name ?? ''),
                'Period: '.$subject->from_date->format('d M Y').' to '.$subject->to_date->format('d M Y'),
                "Employees: {$employees}, planned hours: {$hours}",
                $subject->creator ? 'Prepared by '.($subject->creator->employee?->full_name ?? $subject->creator->name) : null,
            ])),
            'link' => "/roster/rosters/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->department?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageRosters::class)->publish($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageRosters::class)->reject($subject, $comment);
    }
}
