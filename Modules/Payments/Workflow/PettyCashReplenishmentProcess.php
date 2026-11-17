<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManagePettyCash;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PettyCashReplenishmentProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'petty-cash-replenishment';

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
        return 'Petty cash top-up';
    }

    public function description(): string
    {
        return 'Money moved from the bank to bring a petty cash float back to its amount, covering the vouchers paid since the last top-up.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['float.cash', 'from']);
        $paid = $subject->float->vouchers()->where('status', 'approved')->whereNull('petty_cash_replenishment_id')->get();

        return [
            'title' => "{$subject->number} top up {$subject->float?->cash?->name}: ".number_format($subject->amount, 2),
            'lines' => ['From '.$subject->from?->name, $paid->count().' voucher(s) paid since the last top-up: '.number_format($paid->sum('amount'), 2)],
            'link' => '/payments/petty-cash',
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
        app(ManagePettyCash::class)->replenishmentApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePettyCash::class)->replenishmentRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->amount;
    }
}
