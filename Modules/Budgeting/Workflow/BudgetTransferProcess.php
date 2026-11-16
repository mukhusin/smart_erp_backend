<?php

namespace Modules\Budgeting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Actions\ManageTransfers;
use Modules\Budgeting\Models\BudgetTransfer;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class BudgetTransferProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'budget-transfer';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'budgeting';
    }

    public function name(): string
    {
        return 'Budget transfer';
    }

    public function description(): string
    {
        return 'Moving money between budget items (virement), or a supplementary budget. The original budget stays recorded.';
    }

    /** @param BudgetTransfer $subject */
    public function summary(Model $subject): array
    {
        return [
            'title' => ($subject->kind === 'supplementary' ? 'Supplementary budget: ' : 'Budget transfer: ').number_format((float) $subject->amount, 2),
            'lines' => array_values(array_filter([
                $subject->fromItem ? 'From: '.$subject->fromItem->label().' ('.$subject->fromItem->department?->name.')' : null,
                'To: '.$subject->toItem?->label().' ('.$subject->toItem?->department?->name.')',
                'Reason: '.$subject->reason,
            ])),
            'link' => "/budgeting/budgets/{$subject->budget_id}/transfers",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->toItem?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTransfers::class)->apply($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTransfers::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->amount;
    }
}
