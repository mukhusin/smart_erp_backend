<?php

namespace Modules\Budgeting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class DepartmentBudgetProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'department-budget';

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
        return 'Department budget';
    }

    public function description(): string
    {
        return 'A department\'s budget items for the year (sub-activity, account, fund, amount by period).';
    }

    /** @param DepartmentBudget $subject */
    public function summary(Model $subject): array
    {
        $items = $subject->items()->with(['account', 'subActivity', 'activity'])->get();

        return [
            'title' => 'Budget: '.$subject->department?->name,
            'lines' => array_values(array_filter([
                $subject->budget?->title.' · total '.number_format((float) $items->sum('original_amount'), 2),
                ...$items->sortByDesc('original_amount')->take(8)->map(fn ($i) => $i->label().': '.number_format((float) $i->original_amount, 2))->all(),
                $items->count() > 8 ? '… and '.($items->count() - 8).' more items' : null,
            ])),
            'link' => "/budgeting/budgets/{$subject->budget_id}/departments/{$subject->department_id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->department?->company_branch_id;
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
        app(ManageBudgets::class)->departmentApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageBudgets::class)->departmentRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->items()->sum('original_amount');
    }
}
