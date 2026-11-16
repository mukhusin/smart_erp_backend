<?php

namespace Modules\Budgeting\Actions;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Budgeting\Workflow\BudgetApprovalProcess;
use Modules\Budgeting\Workflow\DepartmentBudgetProcess;
use Modules\Core\Notifications\Notice;
use Modules\Planning\Models\SubActivity;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageBudgets
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function create(array $data, User $by): Budget
    {
        if (Budget::where('fiscal_year_id', $data['fiscal_year_id'])->exists()) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'This fiscal year already has a budget.']);
        }

        return Budget::create($data + ['phasing' => AccountingSetting::current()->budget_phasing ?: 'quarter', 'status' => 'draft', 'created_by' => $by->id]);
    }

    public function open(Budget $budget): int
    {
        if (! in_array($budget->status, ['draft', 'open'], true)) {
            throw ValidationException::withMessages(['budget' => 'Only a draft or open budget can be opened.']);
        }

        return DB::transaction(function () use ($budget) {
            $budget->update(['status' => 'open']);
            $existing = $budget->departmentBudgets()->pluck('department_id')->all();
            $created = 0;
            foreach (Department::whereNull('parent_id')->where('is_active', true)->whereNotIn('id', $existing)->get() as $d) {
                DepartmentBudget::create(['budget_id' => $budget->id, 'department_id' => $d->id, 'status' => 'draft']);
                $created++;
            }

            return $created;
        });
    }

    public function saveItem(DepartmentBudget $db, array $data, ?BudgetItem $item = null): BudgetItem
    {
        $this->ensureEditable($db);
        $budget = $db->budget;
        $account = Account::findOrFail($data['account_id']);
        if (! $account->is_budgetable || ! $account->is_postable || ! $account->is_active) {
            throw ValidationException::withMessages(['account_id' => 'Choose an active account marked “can have a budget”.']);
        }
        if (! empty($data['task_activity_id'])) {
            $activity = TaskActivity::find($data['task_activity_id']);
            if (! $activity || $activity->department_id !== $db->department_id || ($budget->annual_plan_id && $activity->annual_plan_id !== $budget->annual_plan_id)) {
                throw ValidationException::withMessages(['task_activity_id' => 'Choose an activity of this department in the year’s plan.']);
            }
        }
        if (! empty($data['sub_activity_id'])) {
            $sub = SubActivity::find($data['sub_activity_id']);
            if (! $sub || (int) $sub->task_activity_id !== (int) ($data['task_activity_id'] ?? 0)) {
                throw ValidationException::withMessages(['sub_activity_id' => 'Choose a sub-activity of the chosen activity.']);
            }
        }
        $total = round((float) $data['quantity'] * (float) $data['unit_cost'] * (float) ($data['frequency'] ?? 1), 2);
        $split = $this->split($total, $budget->periods(), $data['periods'] ?? null);

        return DB::transaction(function () use ($db, $data, $item, $total, $split) {
            $fields = array_intersect_key($data, array_flip(['task_activity_id', 'sub_activity_id', 'account_id', 'fund_source_id', 'description', 'quantity', 'unit', 'unit_cost', 'frequency']))
                + ['original_amount' => $total, 'revised_amount' => $total];
            $item ??= new BudgetItem(['budget_id' => $db->budget_id, 'department_id' => $db->department_id]);
            $item->fill($fields)->save();
            $item->periods()->delete();
            foreach ($split as $i => $amount) {
                $item->periods()->create(['period' => $i + 1, 'amount' => $amount]);
            }

            return $item->load('periods');
        });
    }

    public function deleteItem(DepartmentBudget $db, BudgetItem $item): void
    {
        $this->ensureEditable($db);
        $item->delete();
    }

    public function submitDepartment(DepartmentBudget $db, User $by): DepartmentBudget
    {
        $this->ensureEditable($db);
        if (! $db->items()->exists()) {
            throw ValidationException::withMessages(['budget' => 'Add the department’s budget items before submitting.']);
        }
        $total = (float) $db->items()->sum('original_amount');
        if ($db->ceiling !== null && $total > $db->ceiling) {
            throw ValidationException::withMessages(['budget' => 'The total ('.number_format($total, 2).') is above the department’s ceiling ('.number_format($db->ceiling, 2).').']);
        }

        return DB::transaction(function () use ($db, $by) {
            $db->update(['status' => 'pending', 'submitted_by' => $by->id, 'submitted_at' => now(), 'decision_comment' => null]);
            if (! $this->workflow->start(DepartmentBudgetProcess::KEY, $db, $by)) {
                $this->departmentApproved($db);
            }

            return $db->refresh();
        });
    }

    public function departmentApproved(DepartmentBudget $db): void
    {
        $db->update(['status' => 'approved', 'decided_at' => now()]);
        $db->submitter?->notify(new Notice('Department budget approved', "The {$db->budget->title} budget of {$db->department?->name} is approved.", $this->link($db), 'wallet2'));
    }

    public function departmentRejected(DepartmentBudget $db, ?string $comment, bool $withdrawn): void
    {
        $db->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $db->submitter?->notify(new Notice('Department budget returned', "The {$db->budget->title} budget of {$db->department?->name} was returned: {$comment}", $this->link($db), 'wallet2'));
        }
    }

    public function withdrawDepartment(DepartmentBudget $db, User $by): DepartmentBudget
    {
        if ($db->status !== 'pending') {
            throw ValidationException::withMessages(['budget' => 'Only a budget waiting for approval can be withdrawn.']);
        }
        $instance = $db->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $db->update(['status' => 'draft']);

        return $db->refresh();
    }

    public function reopenDepartment(DepartmentBudget $db): DepartmentBudget
    {
        if ($db->status !== 'approved' || ! $db->budget->editable()) {
            throw ValidationException::withMessages(['budget' => 'Only an approved department budget of a budget still being prepared can be reopened.']);
        }
        $db->update(['status' => 'draft']);

        return $db;
    }

    public function submitFinal(Budget $budget, User $by): Budget
    {
        if (! $budget->editable()) {
            throw ValidationException::withMessages(['budget' => 'This budget is '.strtolower(Budget::STATUS_LABELS[$budget->status]).'.']);
        }
        $waiting = $budget->departmentBudgets()->with('department')->where('status', '!=', 'approved')->get()->filter(fn ($d) => $d->items()->exists() || $d->status === 'pending');
        if ($waiting->isNotEmpty()) {
            throw ValidationException::withMessages(['budget' => 'These department budgets are not approved yet: '.$waiting->map(fn ($d) => $d->department?->name)->join(', ').'.']);
        }
        if (! $budget->items()->exists()) {
            throw ValidationException::withMessages(['budget' => 'The budget has no items yet.']);
        }

        return DB::transaction(function () use ($budget, $by) {
            $budget->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(BudgetApprovalProcess::KEY, $budget, $by)) {
                $this->approved($budget);
            }

            return $budget->refresh();
        });
    }

    public function approved(Budget $budget): void
    {
        DB::transaction(function () use ($budget) {
            $budget->items()->update(['revised_amount' => DB::raw('original_amount')]);
            $budget->update(['status' => 'approved', 'approved_at' => now()]);
        });
    }

    public function rejected(Budget $budget, ?string $comment, bool $withdrawn): void
    {
        $budget->update(['status' => $withdrawn ? 'open' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function close(Budget $budget): Budget
    {
        if ($budget->status !== 'approved') {
            throw ValidationException::withMessages(['budget' => 'Only an approved budget can be closed.']);
        }
        $budget->update(['status' => 'closed']);

        return $budget;
    }

    public function split(float $total, int $periods, ?array $given): array
    {
        if ($given !== null && count($given) === $periods) {
            $given = array_map(fn ($v) => round((float) $v, 2), array_values($given));
            if (abs(array_sum($given) - $total) > 0.01) {
                throw ValidationException::withMessages(['periods' => 'The amounts per period ('.number_format(array_sum($given), 2).') must add up to the total ('.number_format($total, 2).').']);
            }

            return $given;
        }
        $each = floor($total / $periods * 100) / 100;
        $out = array_fill(0, $periods, $each);
        $out[$periods - 1] = round($total - $each * ($periods - 1), 2);

        return $out;
    }

    private function ensureEditable(DepartmentBudget $db): void
    {
        if (! $db->budget->editable()) {
            throw ValidationException::withMessages(['budget' => 'The budget is not open for preparation.']);
        }
        if (! in_array($db->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['budget' => 'This department budget is '.strtolower(DepartmentBudget::STATUS_LABELS[$db->status]).'; it cannot be changed now.']);
        }
    }

    private function link(DepartmentBudget $db): string
    {
        return "/budgeting/budgets/{$db->budget_id}/departments/{$db->department_id}";
    }
}
