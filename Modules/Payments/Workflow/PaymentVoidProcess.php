<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManageVouchers;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PaymentVoidProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'payment-void';

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
        return 'Void a paid payment';
    }

    public function description(): string
    {
        return 'Undoing a payment already made: the money is recovered into the bank, the expense is reversed and the budget is committed again.';
    }

    /** @param PaymentVoucher $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['request.department', 'request.payees', 'bank']);

        return [
            'title' => "Void {$subject->number}: ".number_format((float) $subject->net, 2),
            'lines' => array_values(array_filter([
                "Request: {$subject->request?->number} {$subject->request?->title} ({$subject->request?->department?->name})",
                'Reason: '.$subject->decision_comment,
                $subject->withholding > 0 ? 'Withholding tax: '.number_format($subject->withholding, 2).', net '.number_format($subject->net, 2) : null,
                'Payees: '.$subject->request?->payees->pluck('name')->join(', '),
            ])),
            'link' => "/payments/vouchers/{$subject->id}",
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
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageVouchers::class)->voidApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageVouchers::class)->voidRejected($subject, $comment);
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->gross;
    }
}
