<?php

namespace Modules\Budgeting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Http\Requests\BudgetRequest;
use Modules\Budgeting\Http\Requests\ReleaseRequest;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetRelease;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Budgeting\Services\BudgetService;
use Modules\Budgeting\Support\BudgetAccess;
use Modules\Budgeting\Support\BudgetFigures;

/**
 * @tags Budgeting
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetAccess $access, private readonly ManageBudgets $budgets, private readonly BudgetFigures $figures) {}

    /** @response array{data: list<array{id: int, title: string, fiscal_year: string|null, phasing: string, status: string, status_label: string, annual_plan: string|null, total: float}>} */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesAll($request->user()) || $this->access->departments($request->user()) !== [], 403);
        $budgets = Budget::with(['fiscalYear', 'annualPlan'])->withSum('items as total', 'revised_amount')->withSum('items as original_total', 'original_amount')->latest('id')->get();

        return response()->json(['data' => $budgets->map(fn ($b) => $this->row($b))->values()]);
    }

    /** @response array{data: array{id: int, title: string, fiscal_year: string|null, phasing: string, status: string, status_label: string, annual_plan: string|null, total: float}} */
    public function store(BudgetRequest $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);

        return response()->json(['data' => $this->row($this->budgets->create($request->validated(), $request->user()))], 201);
    }

    /** @response array{data: array{id: int, title: string, fiscal_year: string|null, phasing: string, status: string, status_label: string, annual_plan: string|null, total: float}} */
    public function update(BudgetRequest $request, Budget $budget): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        if (! in_array($budget->status, ['draft', 'open', 'rejected'], true)) {
            throw ValidationException::withMessages(['budget' => 'An approved budget changes only through transfers.']);
        }
        $budget->update($request->validated());

        return response()->json(['data' => $this->row($budget)]);
    }

    /**
     * A budget with its department budgets and totals (original, revised, actual, committed, available).
     *
     * @response array{data: array{id: int, title: string, fiscal_year: string|null, phasing: string, status: string, status_label: string, annual_plan: string|null, total: float, submission_due: string|null, decision_comment: string|null, waiting_for: string|null, period_labels: list<string>}, departments: list<array{id: int, department_id: int, name: string, status: string, status_label: string, ceiling: float|null, items: int, original: float, revised: float, released: float, reserved: float, actual: float, committed: float, available: float, waiting_for: string|null, can_prepare: bool}>, totals: array{original: float, revised: float, released: float, reserved: float, actual: float, committed: float, available: float}, periods: list<array{period: int, label: string, amount: float, status: string, status_label: string, waiting_for: string|null}>, release_mode: string, can: array{manage: bool, open: bool, submit: bool, close: bool, release: bool}}
     */
    public function show(Request $request, Budget $budget): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->seesAll($user) || $this->access->departments($user) !== [], 403);
        $budget->load(['fiscalYear.periods', 'annualPlan', 'workflow.steps']);
        $rows = $this->figures->items($budget->items()->get())->groupBy('department_id');
        $dbs = $budget->departmentBudgets()->with(['department', 'workflow.steps'])->get()->filter(fn ($d) => $this->access->canView($user, $d->department_id))->sortBy(fn ($d) => $d->department?->name);
        $departments = $dbs->map(function (DepartmentBudget $d) use ($rows, $budget, $user) {
            $r = $rows[$d->department_id] ?? collect();

            return ['id' => $d->id, 'department_id' => $d->department_id, 'name' => $d->department?->name ?? '—', 'status' => $d->status,
                'status_label' => DepartmentBudget::STATUS_LABELS[$d->status] ?? $d->status, 'ceiling' => $d->ceiling, 'items' => $r->count()] + $this->figures->totals($r) + [
                    'waiting_for' => $d->status === 'pending' ? $d->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                    'can_prepare' => $budget->editable() && in_array($d->status, ['draft', 'rejected'], true) && $this->access->canPrepare($user, $d->department_id)];
        })->values();
        $manage = $this->access->manages($user);
        $releases = $budget->releases()->with('workflow.steps')->latest('id')->get()->groupBy('period');
        $labels = $budget->periodLabels();
        $items = $budget->items()->with('periods')->get();
        $periods = collect(range(1, $budget->periods()))->map(function ($n) use ($releases, $labels, $items) {
            $r = ($releases[$n] ?? collect())->first();

            return ['period' => $n, 'label' => $labels[$n - 1] ?? 'Period '.$n, 'amount' => round((float) $items->sum(fn ($i) => $this->budgets->periodShare($i, $n)), 2),
                'status' => $r?->status ?? 'closed', 'status_label' => $r ? (BudgetRelease::STATUS_LABELS[$r->status] ?? $r->status) : 'Not open',
                'waiting_for' => $r?->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null];
        })->values();

        return response()->json([
            'data' => $this->row($budget) + ['submission_due' => $budget->submission_due?->toDateString(), 'decision_comment' => $budget->decision_comment,
                'waiting_for' => $budget->status === 'pending' ? $budget->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'period_labels' => $budget->periodLabels()],
            'departments' => $departments,
            'totals' => $this->figures->totals($departments->map(fn ($d) => $d)),
            'periods' => $periods,
            'release_mode' => app(BudgetService::class)->releases() ? 'period' : 'none',
            'can' => ['manage' => $manage, 'open' => $manage && in_array($budget->status, ['draft', 'open'], true), 'submit' => $manage && $budget->editable(), 'close' => $manage && $budget->status === 'approved',
                'release' => $manage && $budget->status === 'approved' && app(BudgetService::class)->releases()],
        ]);
    }

    /** @response array{data: array{created: int}} */
    public function open(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);

        return response()->json(['data' => ['created' => $this->budgets->open($budget)]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function submit(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $b = $this->budgets->submitFinal($budget, $request->user());

        return response()->json(['data' => ['id' => $b->id, 'status' => $b->status]]);
    }

    /**
     * Open a quarter or month of the approved budget (through approval), making its money available to spend.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function release(ReleaseRequest $request, Budget $budget): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $r = $this->budgets->requestRelease($budget, (int) $request->validated()['period'], $request->user());

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]], 201);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function close(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $b = $this->budgets->close($budget);

        return response()->json(['data' => ['id' => $b->id, 'status' => $b->status]]);
    }

    private function row(Budget $b): array
    {
        return ['id' => $b->id, 'title' => $b->title, 'fiscal_year' => $b->fiscalYear?->name, 'phasing' => $b->phasing, 'status' => $b->status,
            'status_label' => Budget::STATUS_LABELS[$b->status] ?? $b->status, 'annual_plan' => $b->annualPlan?->title,
            'total' => round((float) ($b->status === 'approved' || $b->status === 'closed' ? ($b->total ?? $b->items()->sum('revised_amount')) : ($b->original_total ?? $b->items()->sum('original_amount'))), 2)];
    }
}
