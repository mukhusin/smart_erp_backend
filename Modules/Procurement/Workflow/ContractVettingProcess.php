<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ContractVettingProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'contract-vetting';

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
        return 'Contract vetting';
    }

    public function description(): string
    {
        return 'Procurement, legal and the approver vet a contract before it is signed.';
    }

    /** @param \Modules\Procurement\Models\Contract $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Contract {$subject->number}: {$subject->title}", 'lines' => [$subject->supplier?->name.' · '.number_format($subject->value, 2), $subject->start_date?->format('d M Y').' to '.$subject->end_date?->format('d M Y')], 'link' => "/procurement/contracts/{$subject->id}"];
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
        return (float) $subject->value;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageContracts::class)->signed($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageContracts::class)->vettingRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
