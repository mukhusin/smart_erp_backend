<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Procurement\Actions\ManagePlans;
use Modules\Procurement\Http\Requests\PlanRequest;
use Modules\Procurement\Models\ProcurementMethod;
use Modules\Procurement\Models\ProcurementPlan;
use Modules\Procurement\Models\ProcurementPlanLine;
use Modules\Procurement\Support\ProcurementAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Procurement: annual plan
 */
class PlanController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManagePlans $plans) {}

    /**
     * Procurement plans, one per budget year.
     *
     * @response array{data: list<array{id: int, title: string, year: string|null, budget_id: int, status: string, status_label: string, revision: int, lines: int, total: float, requisitioned: float, approved_at: string|null, waiting_for: string|null}>, budgets: list<array{id: int, title: string, year: string|null, has_plan: bool}>, can_manage: bool}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()) || $this->access->can($request->user(), 'request'), 403);
        $plans = ProcurementPlan::with(['budget.fiscalYear', 'workflow.steps'])->withCount('lines')->withSum('lines', 'amount')->latest('id')->get();
        $used = $this->plans->used(ProcurementPlanLine::whereIn('procurement_plan_id', $plans->pluck('id'))->pluck('id'));
        $byPlan = ProcurementPlanLine::whereIn('procurement_plan_id', $plans->pluck('id'))->get(['id', 'procurement_plan_id'])->groupBy('procurement_plan_id')
            ->map(fn ($g) => round($g->sum(fn ($l) => $used[$l->id]['requisitioned'] ?? 0), 2));

        return response()->json([
            'data' => $plans->map(fn (ProcurementPlan $p) => ['id' => $p->id, 'title' => $p->title, 'year' => $p->budget?->fiscalYear?->name, 'budget_id' => $p->budget_id, 'status' => $p->status,
                'status_label' => ProcurementPlan::STATUS_LABELS[$p->status] ?? $p->status, 'revision' => $p->revision, 'lines' => (int) $p->lines_count, 'total' => round((float) $p->lines_sum_amount, 2),
                'requisitioned' => (float) ($byPlan[$p->id] ?? 0), 'approved_at' => $p->approved_at?->toIso8601String(),
                'waiting_for' => $p->status === 'pending' ? $p->workflow?->steps->firstWhere('status', 'pending')?->name : null])->values(),
            'budgets' => Budget::with('fiscalYear')->whereIn('status', ['draft', 'submitted', 'approved'])->latest('id')->get()->map(fn ($b) => ['id' => $b->id, 'title' => $b->title,
                'year' => $b->fiscalYear?->name, 'has_plan' => $plans->contains('budget_id', $b->id)])->values(),
            'can_manage' => $this->access->can($request->user(), 'manage'),
        ]);
    }

    /** @response array{data: array{id: int}} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $data = $request->validate([
            'budget_id' => ['required', 'integer', 'exists:budgets,id'],
            'title' => ['nullable', 'string', 'max:200'],
            /** Start from the budget lines of expense and asset accounts. */
            'from_budget' => ['boolean'],
        ]);
        $plan = $this->plans->create($data, $request->user());

        return response()->json(['data' => ['id' => $plan->id]], 201);
    }

    /**
     * A plan with its lines and how far each line has gone.
     *
     * @response array{data: array{id: int, title: string, year: string|null, budget_id: int, status: string, status_label: string, revision: int, decision_comment: string|null, approved_at: string|null, waiting_for: string|null, total: float, requisitioned: float, lines: list<array{id: int, department_id: int, department: string|null, budget_item_id: int|null, budget_line: string|null, item_id: int|null, description: string, category: string, category_label: string, procurement_method_id: int|null, method: string|null, unit_id: int|null, unit: string|null, quantity: float, estimated_unit_cost: float, amount: float, quarter: int, notes: string|null, requisitioned: float, ordered: float, progress: string, quarter_ends: string|null}>, by_quarter: list<array{quarter: int, amount: float, requisitioned: float}>, can: array{edit: bool, submit: bool, withdraw: bool, revise: bool}}, options: array{categories: list<array{value: string, label: string}>, methods: list<array{id: int, code: string, name: string, category: string|null, min_amount: float|null, max_amount: float|null}>, budget_lines: list<array{id: int, department_id: int, label: string}>}}
     */
    public function show(Request $request, ProcurementPlan $plan): JsonResponse
    {
        $user = $request->user();
        $p = $plan->load(['budget.fiscalYear', 'lines.department', 'lines.budgetItem.account', 'lines.budgetItem.activity', 'lines.budgetItem.subActivity', 'lines.method', 'lines.unit', 'workflow.steps']);
        $approver = $p->workflow && ($p->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $p->workflow));
        abort_unless($this->access->sees($user) || $this->access->can($user, 'request') || $approver, 403);
        $manage = $this->access->can($user, 'manage');
        $prog = $this->plans->progress($p);
        $lines = $p->lines->map(fn (ProcurementPlanLine $l) => ['id' => $l->id, 'department_id' => $l->department_id, 'department' => $l->department?->name, 'budget_item_id' => $l->budget_item_id,
            'budget_line' => $l->budgetItem?->label(), 'item_id' => $l->item_id, 'description' => $l->description, 'category' => $l->category,
            'category_label' => ProcurementMethod::CATEGORIES[$l->category] ?? $l->category, 'procurement_method_id' => $l->procurement_method_id, 'method' => $l->method?->name,
            'unit_id' => $l->unit_id, 'unit' => $l->unit?->code, 'quantity' => $l->quantity, 'estimated_unit_cost' => $l->estimated_unit_cost, 'amount' => $l->amount, 'quarter' => $l->quarter,
            'notes' => $l->notes, 'requisitioned' => (float) $prog[$l->id]['requisitioned'], 'ordered' => (float) $prog[$l->id]['ordered'], 'progress' => $prog[$l->id]['status'],
            'quarter_ends' => $prog[$l->id]['quarter_ends']])->values();

        return response()->json([
            'data' => ['id' => $p->id, 'title' => $p->title, 'year' => $p->budget?->fiscalYear?->name, 'budget_id' => $p->budget_id, 'status' => $p->status,
                'status_label' => ProcurementPlan::STATUS_LABELS[$p->status] ?? $p->status, 'revision' => $p->revision, 'decision_comment' => $p->decision_comment,
                'approved_at' => $p->approved_at?->toIso8601String(), 'waiting_for' => $p->status === 'pending' ? $p->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'total' => round($lines->sum('amount'), 2), 'requisitioned' => round($lines->sum('requisitioned'), 2), 'lines' => $lines,
                'by_quarter' => collect([1, 2, 3, 4])->map(fn ($q) => ['quarter' => $q, 'amount' => round($lines->where('quarter', $q)->sum('amount'), 2),
                    'requisitioned' => round($lines->where('quarter', $q)->sum('requisitioned'), 2)])->values(),
                'can' => ['edit' => $manage && $p->editable(), 'submit' => $manage && $p->editable(), 'withdraw' => $manage && $p->status === 'pending', 'revise' => $manage && $p->status === 'approved']],
            'options' => [
                'categories' => collect(ProcurementMethod::CATEGORIES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'methods' => ProcurementMethod::where('is_active', true)->orderBy('code')->get()->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'category' => $m->category,
                    'min_amount' => $m->min_amount, 'max_amount' => $m->max_amount])->values(),
                'budget_lines' => $p->editable() && $manage ? BudgetItem::with(['account', 'activity', 'subActivity'])->where('budget_id', $p->budget_id)
                    ->whereHas('account', fn ($q) => $q->whereIn('type', ['expense', 'asset']))->get()->map(fn ($i) => ['id' => $i->id, 'department_id' => $i->department_id, 'label' => $i->label()])->values() : [],
            ],
        ]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function update(PlanRequest $request, ProcurementPlan $plan): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $p = $this->plans->save($plan, $request->validated());

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function submit(Request $request, ProcurementPlan $plan): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $p = $this->plans->submit($plan, $request->user());

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function withdraw(Request $request, ProcurementPlan $plan): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $p = $this->plans->withdraw($plan, $request->user());

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function revise(Request $request, ProcurementPlan $plan): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $p = $this->plans->revise($plan);

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    /**
     * Lines of the approved plan for a department, with what is left, for linking requisition lines.
     *
     * @response array{data: list<array{id: int, label: string, budget_item_id: int|null, item_id: int|null, unit_id: int|null, estimated_unit_cost: float, amount: float, left: float}>}
     */
    #[QueryParameter('department_id', type: 'integer', required: true)]
    #[QueryParameter('budget_id', description: 'Empty for the approved budget of the current year.', type: 'integer')]
    public function lines(Request $request): JsonResponse
    {
        $department = (int) $request->query('department_id');
        abort_unless($this->access->canRequest($request->user(), $department) || $this->access->sees($request->user()), 403);
        $budgetId = (int) $request->query('budget_id') ?: Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->value('id');
        $plan = $budgetId ? ProcurementPlan::approvedFor($budgetId) : null;
        $lines = $plan ? ProcurementPlanLine::where('procurement_plan_id', $plan->id)->where('department_id', $department)->orderBy('quarter')->orderBy('id')->get() : collect();
        $used = $this->plans->used($lines->pluck('id'));

        return response()->json(['data' => $lines->map(fn ($l) => ['id' => $l->id, 'label' => "Q{$l->quarter} {$l->description}", 'budget_item_id' => $l->budget_item_id, 'item_id' => $l->item_id,
            'unit_id' => $l->unit_id, 'estimated_unit_cost' => $l->estimated_unit_cost, 'amount' => $l->amount, 'left' => round(max(0, $l->amount - ($used[$l->id]['requisitioned'] ?? 0)), 2)])->values()]);
    }
}
