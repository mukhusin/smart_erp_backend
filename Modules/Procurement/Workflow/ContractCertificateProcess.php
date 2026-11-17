<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ContractCertificateProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'contract-certificate';

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
        return 'Contract certificate';
    }

    public function description(): string
    {
        return 'A progress or completion certificate for works, services or consultancy; approval sends it for payment.';
    }

    /** @param \Modules\Procurement\Models\ContractCertificate $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Certificate {$subject->number} on {$subject->contract->number}", 'lines' => array_values(array_filter(['Certified '.number_format($subject->gross, 2).' · net '.number_format($subject->net, 2), $subject->period, $subject->description])), 'link' => "/procurement/contracts/{$subject->contract_id}"];
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
        return (float) $subject->gross;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageContracts::class)->certificateApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageContracts::class)->certificateRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
