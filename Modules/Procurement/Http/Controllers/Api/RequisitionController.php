<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Procurement\Actions\ManageRequisitions;
use Modules\Procurement\Http\Requests\ProcurementSettingsRequest;
use Modules\Procurement\Http\Requests\ReasonRequest;
use Modules\Procurement\Http\Requests\RequisitionRequest;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\QuotationRequest;
use Modules\Procurement\Support\ProcurementAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Procurement: requisitions
 */
class RequisitionController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageRequisitions $requisitions) {}

    /**
     * Purchase requisitions the user can see.
     *
     * @response array{data: list<array{id: int, number: string, title: string, department: string|null, kind: string, total: float, status: string, status_label: string, over_budget: bool, needed_by: string|null, requested_by: string|null, waiting_for: string|null, created_at: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('mine', type: 'boolean')]
    #[QueryParameter('sort', description: '`created_at`, `total`, `number`; prefix `-` for descending.', type: 'string', default: '-created_at')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $departments = $this->access->departments($user);
        $sortParam = (string) $request->query('sort', '-created_at');
        $sort = in_array(ltrim($sortParam, '-'), ['created_at', 'total', 'number'], true) ? ltrim($sortParam, '-') : 'created_at';
        $page = PurchaseRequisition::with(['department', 'requester', 'workflow.steps'])->inAuditPeriod('created_at')
            ->where(fn ($q) => $q->where('requested_by', $user->id)->when($departments !== null, fn ($q) => $q->orWhereIn('department_id', $departments)->where('status', '!=', 'draft'), fn ($q) => $q->orWhere('status', '!=', 'draft')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (PurchaseRequisition $r) => [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'department' => $r->department?->name, 'kind' => $r->kind, 'total' => (float) $r->total, 'status' => $r->status,
            'status_label' => PurchaseRequisition::STATUS_LABELS[$r->status] ?? $r->status, 'over_budget' => (bool) $r->over_budget, 'needed_by' => $r->needed_by?->toDateString(),
            'requested_by' => $r->requester?->display_name, 'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'created_at' => $r->created_at->toIso8601String(),
        ])->values(), 'meta' => $this->meta($page)]);
    }

    /**
     * A requisition with lines, what is ordered, quotation requests and orders.
     *
     * @response array{data: array{id: int, number: string, title: string, purpose: string|null, department_id: int, department: string|null, store_id: int|null, store: string|null, kind: string, special_reason: string|null, needed_by: string|null, delivery_place: string|null, total: float, status: string, status_label: string, over_budget: bool, requested_by: string|null, decision_comment: string|null, waiting_for: string|null, lines: list<array{id: int, item_id: int|null, item_code: string|null, description: string, specification: string|null, unit_id: int|null, unit: string|null, quantity: float, estimated_unit_cost: float, amount: float, budget_item_id: int, budget_line: string|null, ordered_quantity: float, remaining: float}>, rfqs: list<array{id: int, number: string, status: string, status_label: string, closing_date: string}>, orders: list<array{id: int, number: string, supplier: string|null, total: float, status: string, status_label: string}>, can: array{edit: bool, submit: bool, withdraw: bool, cancel: bool, source: bool}}}
     */
    public function show(Request $request, PurchaseRequisition $requisition): JsonResponse
    {
        $user = $request->user();
        $r = $requisition->load(['department', 'store', 'lines.item', 'lines.unit', 'lines.budgetItem.account', 'lines.budgetItem.subActivity', 'lines.budgetItem.activity', 'quotationRequests', 'orders.supplier', 'requester', 'workflow.steps']);
        $this->authorizeView($user, $r);
        $mine = $r->requested_by === $user->id;

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'purpose' => $r->purpose, 'department_id' => $r->department_id, 'department' => $r->department?->name,
            'store_id' => $r->store_id, 'store' => $r->store?->name, 'kind' => $r->kind, 'special_reason' => $r->special_reason, 'needed_by' => $r->needed_by?->toDateString(),
            'delivery_place' => $r->delivery_place, 'total' => (float) $r->total, 'status' => $r->status, 'status_label' => PurchaseRequisition::STATUS_LABELS[$r->status] ?? $r->status,
            'over_budget' => (bool) $r->over_budget, 'requested_by' => $r->requester?->display_name, 'decision_comment' => $r->decision_comment,
            'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'item_code' => $l->item?->code, 'description' => $l->description, 'specification' => $l->specification,
                'unit_id' => $l->unit_id, 'unit' => $l->unit?->code, 'quantity' => $l->quantity, 'estimated_unit_cost' => $l->estimated_unit_cost, 'amount' => $l->amount,
                'budget_item_id' => $l->budget_item_id, 'budget_line' => $l->budgetItem?->label(), 'ordered_quantity' => $l->ordered_quantity, 'remaining' => $l->remaining()])->values(),
            'rfqs' => $r->quotationRequests->map(fn ($q) => ['id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'status_label' => QuotationRequest::STATUS_LABELS[$q->status] ?? $q->status,
                'closing_date' => $q->closing_date->toDateString()])->values(),
            'orders' => $r->orders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'supplier' => $o->supplier?->name, 'total' => (float) $o->total, 'status' => $o->status,
                'status_label' => PurchaseOrder::STATUS_LABELS[$o->status] ?? $o->status])->values(),
            'can' => ['edit' => $r->editable() && $mine, 'submit' => $r->editable() && $mine, 'withdraw' => $r->status === 'pending' && $mine,
                'cancel' => ($r->editable() && $mine) || (in_array($r->status, ['approved', 'ordered'], true) && $this->access->buyer($user)),
                'source' => $r->status === 'approved' && $this->access->buyer($user)],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(RequisitionRequest $request): JsonResponse
    {
        abort_unless($this->access->canRequest($request->user(), (int) $request->validated('department_id')), 403, 'You can raise requisitions only for your departments.');
        $r = $this->requisitions->save(null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(RequisitionRequest $request, PurchaseRequisition $requisition): JsonResponse
    {
        abort_unless($requisition->requested_by === $request->user()->id && $this->access->canRequest($request->user(), (int) $request->validated('department_id')), 403);
        $r = $this->requisitions->save($requisition, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, PurchaseRequisition $requisition): JsonResponse
    {
        abort_unless($requisition->requested_by === $request->user()->id, 403);
        $r = $this->requisitions->submit($requisition->load('lines'), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, PurchaseRequisition $requisition): JsonResponse
    {
        $r = $this->requisitions->withdraw($requisition, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function cancel(ReasonRequest $request, PurchaseRequisition $requisition): JsonResponse
    {
        $user = $request->user();
        abort_unless($requisition->requested_by === $user->id || $this->access->buyer($user), 403);
        $r = $this->requisitions->cancel($requisition->load('lines'), $user, $request->validated('reason'));

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /**
     * Choice lists for procurement forms: departments the user can request for, stores, items, units, suppliers.
     *
     * @response array{data: array{departments: list<array{id: int, name: string}>, stores: list<array{id: int, name: string}>, items: list<array{id: int, code: string, name: string, unit_id: int, last_cost: float|null}>, units: list<array{id: int, code: string}>, suppliers: list<array{id: int, name: string, code: string}>, methods: list<array{value: string, label: string}>, direct_limit: float, min_quotes: int}}
     */
    public function lookups(Request $request, \Modules\Hr\Support\EmployeeScope $scope): JsonResponse
    {
        $user = $request->user();
        $ids = null;
        if (! $this->access->can($user, 'manage')) {
            $rules = $this->access->can($user, 'request') ? $scope->rules($user) : ['departments' => []];
            $ids = $rules === null ? null : $rules['departments'];
        }
        $s = ProcurementSetting::current();

        return response()->json(['data' => [
            'departments' => Department::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'stores' => \Modules\Inventory\Models\Store::where('is_active', true)->where('kind', '!=', 'transit')->orderBy('name')->get()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values(),
            'items' => \Modules\Inventory\Models\Item::where('is_active', true)->orderBy('name')->limit(2000)->get()->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit_id' => $i->unit_id, 'last_cost' => $i->last_cost])->values(),
            'units' => \Modules\Inventory\Models\Unit::where('is_active', true)->orderBy('code')->get()->map(fn ($u) => ['id' => $u->id, 'code' => $u->code])->values(),
            'suppliers' => \Modules\Procurement\Models\Supplier::where('is_active', true)->where('blacklisted', false)->orderBy('name')->get()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'code' => $x->code])->values(),
            'methods' => collect(PurchaseOrder::METHODS)->except('quotation')->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'direct_limit' => (float) $s->direct_limit, 'min_quotes' => (int) $s->min_quotes,
        ]]);
    }

    /**
     * Budget lines of a department in the current approved budget with what is available.
     *
     * @response array{data: list<array{id: int, label: string, available: float}>}
     */
    #[QueryParameter('department_id', type: 'integer', required: true)]
    public function budgetLines(Request $request, BudgetService $budgets): JsonResponse
    {
        $department = (int) $request->query('department_id');
        abort_unless($this->access->canRequest($request->user(), $department) || $this->access->sees($request->user()), 403);
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        $items = $budget ? BudgetItem::with(['account', 'subActivity', 'activity'])->where('budget_id', $budget->id)->where('department_id', $department)->whereHas('account', fn ($q) => $q->where('type', '!=', 'revenue'))->get() : collect();
        $f = $budgets->figures($items);

        return response()->json(['data' => $items->map(fn ($i) => ['id' => $i->id, 'label' => $i->label(), 'available' => $f[$i->id]['available']])->values()]);
    }

    /** @response array{data: array{clearing_account_id: int|null, direct_limit: float, min_quotes: int, match_tolerance_percent: float, retention_account_id: int|null, damages_account_id: int|null, advance_account_id: int|null, variation_limit_percent: float, expiry_warning_days: int, award_acceptance_days: int}, accounts: list<array{id: int, code: string, name: string}>, all_accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $s = ProcurementSetting::current();

        return response()->json(['data' => ['clearing_account_id' => $s->clearing_account_id, 'direct_limit' => (float) $s->direct_limit, 'min_quotes' => (int) $s->min_quotes,
            'match_tolerance_percent' => (float) $s->match_tolerance_percent, 'retention_account_id' => $s->retention_account_id, 'damages_account_id' => $s->damages_account_id,
            'advance_account_id' => $s->advance_account_id, 'variation_limit_percent' => (float) $s->variation_limit_percent, 'expiry_warning_days' => (int) $s->expiry_warning_days,
            'award_acceptance_days' => (int) $s->award_acceptance_days],
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->where('type', 'liability')->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
            'all_accounts' => Account::where('is_active', true)->where('is_postable', true)->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values()]);
    }

    /** @response array{data: array{clearing_account_id: int|null, direct_limit: float, min_quotes: int, match_tolerance_percent: float, retention_account_id: int|null, damages_account_id: int|null, advance_account_id: int|null, variation_limit_percent: float, expiry_warning_days: int, award_acceptance_days: int}, accounts: list<array{id: int, code: string, name: string}>, all_accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function updateSettings(ProcurementSettingsRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        ProcurementSetting::current()->fill($request->validated())->save();

        return $this->settings($request);
    }

    private function authorizeView($user, PurchaseRequisition $r): void
    {
        $departments = $this->access->departments($user);
        $approver = $r->workflow && ($r->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $r->workflow));
        abort_unless($r->requested_by === $user->id || $approver || ($r->status !== 'draft' && ($departments === null || in_array($r->department_id, $departments, true))), 403);
    }

    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }
}
