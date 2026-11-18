<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageRequisitions;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PurchaseRequisitionProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'purchase-requisition';

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
        return 'Purchase requisition';
    }

    public function description(): string
    {
        return 'A department asks procurement to buy items from its budget lines. The money is held on the budget while it waits.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['department', 'lines']);

        return [
            'title' => "{$subject->number} {$subject->title}: ".number_format((float) $subject->total, 2),
            'lines' => array_values(array_filter([
                'Department: '.$subject->department?->name,
                $subject->kind === 'special' ? 'Special / emergency: '.$subject->special_reason : null,
                $subject->over_budget ? 'Over the available budget.' : null,
                $subject->unplanned ? 'Outside the approved procurement plan.' : null,
                ...$subject->lines->take(8)->map(fn ($l) => "{$l->description}: {$l->quantity} × ".number_format($l->estimated_unit_cost, 2))->all(),
            ])),
            'link' => "/procurement/requisitions/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
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
        app(ManageRequisitions::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageRequisitions::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
