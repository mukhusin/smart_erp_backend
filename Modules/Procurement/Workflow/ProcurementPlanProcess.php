<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManagePlans;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ProcurementPlanProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'procurement-plan';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'procurement';
    }

    public function name(): string
    {
        return 'Annual procurement plan';
    }

    public function description(): string
    {
        return 'What the organisation plans to buy in the year, by department, quarter and method. Requisitions are checked against the approved plan.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['budget.fiscalYear', 'lines.department']);
        $byDept = $subject->lines->groupBy(fn ($l) => $l->department?->name ?? '—')->map(fn ($g, $d) => "{$d}: ".number_format($g->sum('amount'), 2))->take(8)->values()->all();

        return [
            'title' => "{$subject->title}".($subject->revision ? " (revision {$subject->revision})" : '').': '.number_format($subject->lines->sum('amount'), 2),
            'lines' => array_values(array_filter(['Year: '.$subject->budget?->fiscalYear?->name, $subject->lines->count().' lines', ...$byDept])),
            'link' => "/procurement/plans/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return null;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManagePlans::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePlans::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->lines()->sum('amount');
    }
}
