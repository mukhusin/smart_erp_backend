<?php

namespace Modules\Budgeting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Models\Budget;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class BudgetApprovalProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'budget-approval';

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
        return 'Annual budget';
    }

    public function description(): string
    {
        return 'The whole budget of the year, after the department budgets are approved. Once approved it is the original budget and is locked.';
    }

    /** @param Budget $subject */
    public function summary(Model $subject): array
    {
        $byDept = $subject->items()->with('department')->get()->groupBy(fn ($i) => $i->department?->name ?? '—');

        return [
            'title' => 'Budget: '.$subject->title,
            'lines' => array_values(array_filter([
                'Total '.number_format((float) $subject->items()->sum('original_amount'), 2),
                ...$byDept->map(fn ($g, $name) => $name.': '.number_format((float) $g->sum('original_amount'), 2))->take(12)->values()->all(),
            ])),
            'link' => "/budgeting/budgets/{$subject->id}",
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
        app(ManageBudgets::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageBudgets::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->items()->sum('original_amount');
    }
}
