<?php

namespace Modules\Accounting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Actions\ManageReconciliations;
use Modules\Accounting\Models\BankReconciliation;
use Modules\Workflow\Contracts\WorkflowProcess;

class BankReconciliationProcess implements WorkflowProcess
{
    public const KEY = 'bank-reconciliation';

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
        return 'Bank reconciliation';
    }

    public function description(): string
    {
        return 'A bank or cash account agreed with its statement: what is cleared, what is still outstanding and the items booked from the statement.';
    }

    /** @param BankReconciliation $subject */
    public function summary(Model $subject): array
    {
        $s = app(ManageReconciliations::class)->summary($subject->loadMissing('bank'));

        return [
            'title' => "{$subject->number} {$subject->bank?->name} to {$subject->statement_date->format('d M Y')}",
            'lines' => [
                'Statement balance '.number_format($s['statement_balance'], 2).' · ledger balance '.number_format($s['book_balance'], 2),
                'Outstanding payments '.number_format($s['uncleared_payments'], 2).' · deposits not yet on the statement '.number_format($s['uncleared_receipts'], 2),
                'Difference '.number_format($s['difference'], 2),
            ],
            'link' => "/accounting/reconciliations/{$subject->id}",
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
        app(ManageReconciliations::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageReconciliations::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
