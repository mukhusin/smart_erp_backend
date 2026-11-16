<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManageLists;
use Modules\Payments\Models\PaymentList;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PaymentListProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'payment-list';

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
        return 'Payment list';
    }

    public function description(): string
    {
        return 'The cashier groups approved vouchers of one bank account. On approval the payees are paid from the bank.';
    }

    /** @param PaymentList $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['bank', 'items.voucher', 'items.payee']);

        return [
            'title' => "{$subject->number}: ".number_format((float) $subject->total, 2),
            'lines' => [
                'From: '.$subject->bank?->name,
                'Vouchers: '.$subject->items->pluck('voucher.number')->unique()->join(', '),
                'Payees: '.$subject->items->count(),
            ],
            'link' => "/payments/lists/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return null;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageLists::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageLists::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
