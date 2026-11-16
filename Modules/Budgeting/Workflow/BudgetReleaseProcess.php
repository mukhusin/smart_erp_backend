<?php

namespace Modules\Budgeting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Models\BudgetRelease;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class BudgetReleaseProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'budget-release';

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
        return 'Open a budget period';
    }

    public function description(): string
    {
        return 'Opening a quarter or month of the approved budget, which makes its money available to spend.';
    }

    /** @param BudgetRelease $subject */
    public function summary(Model $subject): array
    {
        $labels = $subject->budget?->load('fiscalYear.periods')->periodLabels() ?? [];

        return [
            'title' => 'Open '.($labels[$subject->period - 1] ?? 'period '.$subject->period).' of '.$subject->budget?->title,
            'lines' => ['Money made available: '.number_format((float) $this->amount($subject), 2)],
            'link' => "/budgeting/budgets/{$subject->budget_id}",
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
        app(ManageBudgets::class)->released($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageBudgets::class)->releaseRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        $manage = app(ManageBudgets::class);

        return round((float) $subject->budget->items()->with('periods')->get()->sum(fn ($i) => $manage->periodShare($i, $subject->period)), 2);
    }
}
