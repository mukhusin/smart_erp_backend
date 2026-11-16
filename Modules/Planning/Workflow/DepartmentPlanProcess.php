<?php

namespace Modules\Planning\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Planning\Actions\ManagePlanning;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Workflow\Contracts\WorkflowProcess;

class DepartmentPlanProcess implements WorkflowProcess
{
    public const KEY = 'department-plan';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'planning';
    }

    public function name(): string
    {
        return 'Department annual plan';
    }

    public function description(): string
    {
        return 'A department\'s activities for the year, with outputs, targets and KPIs. Approved activities can take tasks.';
    }

    /** @param DepartmentPlan $subject */
    public function summary(Model $subject): array
    {
        $activities = $subject->activities()->orderBy('code')->orderBy('title')->get();

        return [
            'title' => 'Annual plan: '.$subject->department?->name,
            'lines' => array_values(array_filter([
                $subject->annualPlan?->title,
                $activities->count().' activit'.($activities->count() === 1 ? 'y' : 'ies'),
                ...$activities->take(10)->map(fn ($a) => trim(($a->code ? $a->code.' ' : '').$a->title).($a->target ? ' — target: '.$a->target : ''))->all(),
                $activities->count() > 10 ? '… and '.($activities->count() - 10).' more' : null,
            ])),
            'link' => "/planning/annual/{$subject->annual_plan_id}/departments/{$subject->department_id}",
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
        app(ManagePlanning::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePlanning::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
