<?php

namespace Modules\Accounting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Actions\ManageOpeningBalances;
use Modules\Accounting\Models\OpeningBalance;
use Modules\Workflow\Contracts\WorkflowProcess;

class OpeningBalanceProcess implements WorkflowProcess
{
    public const KEY = 'opening-balance';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'accounting';
    }

    public function name(): string
    {
        return 'Opening balances';
    }

    public function description(): string
    {
        return 'Balances brought from the old books at go-live: account balances, unpaid supplier bills, unpaid customer invoices and breakdowns.';
    }

    /** @param OpeningBalance $subject */
    public function summary(Model $subject): array
    {
        $lines = $subject->lines()->get();

        return [
            'title' => 'Opening balances: '.(OpeningBalance::KINDS[$subject->kind] ?? $subject->kind),
            'lines' => [
                $subject->number.' · as at '.$subject->date?->format('d M Y'),
                $lines->count().' rows · debits '.number_format($lines->sum('debit'), 2).' · credits '.number_format($lines->sum('credit'), 2),
            ],
            'link' => "/accounting/opening-balances/{$subject->id}",
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
        app(ManageOpeningBalances::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageOpeningBalances::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
