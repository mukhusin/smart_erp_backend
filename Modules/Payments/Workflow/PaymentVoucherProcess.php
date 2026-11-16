<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManageVouchers;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PaymentVoucherProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'payment-voucher';

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
        return 'Payment voucher';
    }

    public function description(): string
    {
        return 'Finance prepares the voucher of an approved request. On approval the expense is posted to the ledger.';
    }

    /** @param PaymentVoucher $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['request.department', 'request.payees', 'bank']);

        return [
            'title' => "{$subject->number}: ".number_format((float) $subject->gross, 2),
            'lines' => array_values(array_filter([
                "Request: {$subject->request?->number} {$subject->request?->title} ({$subject->request?->department?->name})",
                'Pay from: '.$subject->bank?->name,
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
        app(ManageVouchers::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageVouchers::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->gross;
    }
}
