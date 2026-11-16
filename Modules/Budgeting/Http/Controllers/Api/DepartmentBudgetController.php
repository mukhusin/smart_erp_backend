<?php

namespace Modules\Budgeting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FundSource;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Http\Requests\BudgetItemRequest;
use Modules\Budgeting\Http\Requests\CeilingRequest;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Budgeting\Support\BudgetAccess;
use Modules\Budgeting\Support\BudgetFigures;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Budgeting
 */
class DepartmentBudgetController extends Controller
{
    public function __construct(private readonly BudgetAccess $access, private readonly ManageBudgets $budgets, private readonly BudgetFigures $figures) {}

    /**
     * A department's budget: its items with figures, last year's actuals per account, and the plan activities to budget.
     *
     * @response array{data: array{id: int, status: string, status_label: string, ceiling: float|null, decision_comment: string|null, submitted_by: string|null, waiting_for: string|null}, budget: array{id: int, title: string, status: string, phasing: string, period_labels: list<string>}, department: array{id: int, name: string}, items: list<array{id: int, label: string, department_id: int, department: string|null, activity: array{id: int, code: string|null, title: string}|null, sub_activity: array{id: int, code: string|null, title: string}|null, account: array{id: int, code: string, gfs_code: string|null, name: string, type: string}|null, fund_source: array{id: int, code: string}|null, description: string|null, quantity: float, unit: string|null, unit_cost: float, frequency: float, periods: list<float>, original: float, revised: float, released: float, unreleased: float, reserved: float, actual: float, committed: float, available: float, used_percent: float|null}>, totals: array{original: float, revised: float, released: float, reserved: float, actual: float, committed: float, available: float}, last_year: list<array{account_id: int, code: string, name: string, actual: float}>, activities: list<array{id: int, code: string|null, title: string, sub_activities: list<array{id: int, code: string|null, title: string}>}>, accounts: list<array{id: int, code: string, gfs_code: string|null, name: string, type: string}>, fund_sources: list<array{id: int, code: string, name: string}>, can: array{edit: bool, submit: bool, withdraw: bool, reopen: bool, ceiling: bool}}
     */
    public function show(Request $request, Budget $budget, Department $department): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $department->id) || $this->access->canPrepare($user, $department->id), 403);
        $db = $this->find($budget, $department)->load(['submitter', 'workflow.steps']);
        $rows = $this->figures->items(BudgetItem::where('budget_id', $budget->id)->where('department_id', $department->id)->orderBy('id')->get());
        $editable = $budget->editable() && in_array($db->status, ['draft', 'rejected'], true) && $this->access->canPrepare($user, $department->id);

        return response()->json([
            'data' => ['id' => $db->id, 'status' => $db->status, 'status_label' => DepartmentBudget::STATUS_LABELS[$db->status] ?? $db->status, 'ceiling' => $db->ceiling,
                'decision_comment' => $db->decision_comment, 'submitted_by' => $db->submitter?->display_name,
                'waiting_for' => $db->status === 'pending' ? $db->workflow?->steps->firstWhere('status', 'pending')?->name : null],
            'budget' => ['id' => $budget->id, 'title' => $budget->title, 'status' => $budget->status, 'phasing' => $budget->phasing, 'period_labels' => $budget->load('fiscalYear.periods')->periodLabels()],
            'department' => ['id' => $department->id, 'name' => $department->name],
            'items' => $rows->values(),
            'totals' => $this->figures->totals($rows),
            'last_year' => $this->lastYear($budget, $department),
            'activities' => TaskActivity::with('subActivities')->where('department_id', $department->id)
                ->when($budget->annual_plan_id, fn ($q) => $q->where('annual_plan_id', $budget->annual_plan_id), fn ($q) => $q->where('year', $budget->fiscalYear?->start_date?->year))
                ->orderBy('code')->orderBy('title')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'title' => $a->title, 'sub_activities' => $a->subActivities->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'title' => $s->title])->values()])->values(),
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->where('is_budgetable', true)->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'gfs_code' => $a->gfs_code, 'name' => $a->name, 'type' => $a->type])->values(),
            'fund_sources' => FundSource::where('is_active', true)->orderBy('code')->get()->map(fn ($f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values(),
            'can' => [
                'edit' => $editable, 'submit' => $editable,
                'withdraw' => $db->status === 'pending' && $db->submitted_by === $user->id,
                'reopen' => $db->status === 'approved' && $budget->editable() && $this->access->manages($user),
                'ceiling' => $budget->editable() && $this->access->manages($user),
            ],
        ]);
    }

    /** @response array{data: array{id: int}} */
    public function storeItem(BudgetItemRequest $request, Budget $budget, Department $department): JsonResponse
    {
        abort_unless($this->access->canPrepare($request->user(), $department->id), 403);
        $item = $this->budgets->saveItem($this->find($budget, $department), $request->validated());

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateItem(BudgetItemRequest $request, Budget $budget, Department $department, BudgetItem $item): JsonResponse
    {
        abort_unless($this->access->canPrepare($request->user(), $department->id), 403);
        abort_unless($item->budget_id === $budget->id && $item->department_id === $department->id, 404);
        $item = $this->budgets->saveItem($this->find($budget, $department), $request->validated(), $item);

        return response()->json(['data' => ['id' => $item->id]]);
    }

    /** @response array{message: string} */
    public function destroyItem(Request $request, Budget $budget, Department $department, BudgetItem $item): JsonResponse
    {
        abort_unless($this->access->canPrepare($request->user(), $department->id), 403);
        abort_unless($item->budget_id === $budget->id && $item->department_id === $department->id, 404);
        $this->budgets->deleteItem($this->find($budget, $department), $item);

        return response()->json(['message' => 'Budget item removed.']);
    }

    /** @response array{data: array{id: int, ceiling: float|null}} */
    public function ceiling(CeilingRequest $request, Budget $budget, Department $department): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $db = $this->find($budget, $department);
        $db->update(['ceiling' => $request->validated()['ceiling'] ?? null]);

        return response()->json(['data' => ['id' => $db->id, 'ceiling' => $db->ceiling]]);
    }

    /**
     * Submit, withdraw, or (finance) reopen a department budget.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function action(Request $request, Budget $budget, Department $department, string $action): JsonResponse
    {
        $user = $request->user();
        $db = $this->find($budget, $department);
        $db = match ($action) {
            'submit' => $this->access->canPrepare($user, $department->id) ? $this->budgets->submitDepartment($db, $user) : abort(403),
            'withdraw' => $db->submitted_by === $user->id ? $this->budgets->withdrawDepartment($db, $user) : abort(403),
            'reopen' => $this->access->manages($user) ? $this->budgets->reopenDepartment($db) : abort(403),
            default => abort(404),
        };

        return response()->json(['data' => ['id' => $db->id, 'status' => $db->status]]);
    }

    private function find(Budget $budget, Department $department): DepartmentBudget
    {
        return DepartmentBudget::where('budget_id', $budget->id)->where('department_id', $department->id)->firstOr(fn () => abort(404, 'This department has no budget for the year. Open the budget first.'));
    }

    private function lastYear(Budget $budget, Department $department): array
    {
        $start = $budget->fiscalYear?->start_date;
        $prev = $start ? FiscalYear::whereDate('end_date', $start->copy()->subDay())->first() : null;
        if (! $prev) {
            return [];
        }

        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('l.department_id', $department->id)
            ->whereBetween('e.date', [$prev->start_date->toDateString(), $prev->end_date->toDateString()])->where('a.is_budgetable', true)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->selectRaw('a.id, a.code, a.name, a.type, SUM(l.debit) as d, SUM(l.credit) as c')->orderBy('a.code')->get()
            ->map(fn ($r) => ['account_id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'actual' => round($r->type === 'revenue' ? $r->c - $r->d : $r->d - $r->c, 2)])->values()->all();
    }
}
