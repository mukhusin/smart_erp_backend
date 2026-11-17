<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageTenders;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class TenderAwardProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'tender-award';

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
        return 'Tender award';
    }

    public function description(): string
    {
        return 'The tender board award decision per lot.';
    }

    /** @param \Modules\Procurement\Models\Tender $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Award {$subject->number}: {$subject->title}", 'lines' => $subject->awards()->with('supplier')->get()->map(fn ($a) => "{$a->lot}: {$a->supplier->name} ".number_format($a->amount, 2))->all(), 'link' => "/procurement/tenders/{$subject->id}"];
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
        return (float) $subject->awards()->sum('amount');
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTenders::class)->awardApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTenders::class)->awardRejected($subject, $comment);
    }
}
