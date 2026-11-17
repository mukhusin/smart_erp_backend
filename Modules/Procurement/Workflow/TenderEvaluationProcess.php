<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageTenders;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class TenderEvaluationProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'tender-evaluation';

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
        return 'Tender evaluation report';
    }

    public function description(): string
    {
        return 'The evaluation report and ranking, before the award is prepared.';
    }

    /** @param \Modules\Procurement\Models\Tender $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Evaluation report {$subject->number}: {$subject->title}", 'lines' => array_values(array_filter([$subject->bids()->count().' bid(s)', $subject->evaluation_summary])), 'link' => "/procurement/tenders/{$subject->id}"];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->requisition?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->requisition?->total;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTenders::class)->evaluationApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTenders::class)->evaluationRejected($subject, $comment);
    }
}
