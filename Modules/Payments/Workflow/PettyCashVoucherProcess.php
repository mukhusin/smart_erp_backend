<?php

namespace Modules\Payments\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Actions\ManagePettyCash;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PettyCashVoucherProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'petty-cash-voucher';

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
        return 'Petty cash voucher';
    }

    public function description(): string
    {
        return 'A small payment from petty cash, charged to a budget line. Approval pays it out of the cash box in the ledger.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['item', 'float.cash']);

        return [
            'title' => "{$subject->number} petty cash: ".number_format($subject->amount, 2)." to {$subject->payee}",
            'lines' => array_values(array_filter([$subject->description, 'Budget line: '.$subject->item?->label(), 'From '.$subject->float?->cash?->name,
                $subject->over_budget ? 'Over the available budget.' : null])),
            'link' => '/payments/petty-cash',
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->item?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManagePettyCash::class)->voucherApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePettyCash::class)->voucherRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->amount;
    }
}
