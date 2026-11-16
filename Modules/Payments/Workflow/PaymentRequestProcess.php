<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManageRequests;
use Modules\Payments\Models\PaymentRequest;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PaymentRequestProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'payment-request';

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
        return 'Payment request';
    }

    public function description(): string
    {
        return 'A department asks to pay money from its budget lines. The money is held on the budget while it waits.';
    }

    /** @param PaymentRequest $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['lines.item.account', 'lines.item.subActivity', 'lines.item.activity', 'payees', 'department']);

        return [
            'title' => "{$subject->number} {$subject->title}: ".number_format((float) $subject->total, 2),
            'lines' => array_values(array_filter([
                'Department: '.$subject->department?->name,
                $subject->over_budget ? 'Over the available budget — approving spends more than planned.' : null,
                ...$subject->lines->map(fn ($l) => $l->item?->label().': '.number_format($l->amount, 2))->all(),
                'Pay: '.$subject->payees->pluck('name')->join(', '),
            ])),
            'link' => "/payments/requests/{$subject->id}",
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
        app(ManageRequests::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageRequests::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
