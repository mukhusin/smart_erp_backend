<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ContractVariationProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'contract-variation';

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
        return 'Contract variation';
    }

    public function description(): string
    {
        return 'An addendum (scope or price) or extension of time.';
    }

    /** @param \Modules\Procurement\Models\ContractVariation $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Variation {$subject->number} on {$subject->contract->number}", 'lines' => array_values(array_filter([$subject->amount ? 'Price change '.number_format($subject->amount, 2) : null, $subject->new_end_date ? 'New end date '.$subject->new_end_date->format('d M Y') : null, $subject->reason])), 'link' => "/procurement/contracts/{$subject->contract_id}"];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->contract?->requisition?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function amount(Model $subject): ?float
    {
        return abs((float) $subject->amount);
    }

    public function onApproved(Model $subject): void
    {
        app(ManageContracts::class)->variationApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageContracts::class)->variationRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
