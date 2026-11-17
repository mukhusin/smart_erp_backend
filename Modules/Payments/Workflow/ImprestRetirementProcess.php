<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManageRetirements;
use Modules\Payments\Models\ImprestRetirement;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ImprestRetirementProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'imprest-retirement';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'payments';
    }

    public function name(): string
    {
        return 'Imprest retirement';
    }

    public function description(): string
    {
        return 'A staff member accounts for an imprest: receipts for what was spent and the money returned. Approval moves the spending to the budget lines.';
    }

    /** @param ImprestRetirement $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['request.payees', 'lines.item', 'bank']);
        $r = $subject->request;

        return [
            'title' => "{$subject->number} retirement of {$r->number}: ".number_format($subject->advanced, 2),
            'lines' => array_values(array_filter([
                'Holder: '.$r->payees->first()?->name,
                'Spent '.number_format($subject->spent, 2).' · returned '.number_format($subject->returned, 2).($subject->bank ? " to {$subject->bank->name}" : ''),
                ...$subject->lines->map(fn ($l) => $l->item?->label().': '.number_format($l->amount, 2))->all(),
            ])),
            'link' => "/payments/requests/{$r->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->request?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->request?->holder_employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageRetirements::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageRetirements::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->advanced;
    }
}
