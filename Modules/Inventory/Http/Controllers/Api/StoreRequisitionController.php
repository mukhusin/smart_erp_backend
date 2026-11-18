<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\ManageStoreRequisitions;
use Modules\Inventory\Http\Requests\StoreRequisitionRequest;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockDocument;
use Modules\Inventory\Models\StoreRequisition;
use Modules\Inventory\Support\InventoryAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Inventory: store requests
 */
class StoreRequisitionController extends Controller
{
    public function __construct(private readonly InventoryAccess $access, private readonly ManageStoreRequisitions $requisitions) {}

    /**
     * Store requests: mine, or those of the stores I work in.
     *
     * @response array{data: list<array{id: int, number: string, store: string|null, department: string|null, purpose: string|null, items: int, status: string, status_label: string, waiting_for: string|null, requested_by: string|null, created_at: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('mine', type: 'boolean')]
    #[QueryParameter('status', description: 'A status, or `open` for approved and partly issued.', type: 'string')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $stores = $this->access->stores($user);
        $mine = $request->boolean('mine') || ($stores !== null && $stores === []);
        $page = StoreRequisition::with(['store', 'department', 'requester', 'workflow.steps'])->withCount('lines')->inAuditPeriod('created_at')
            ->when($mine, fn ($q) => $q->where('requested_by', $user->id), fn ($q) => $q->where('status', '!=', 'draft')->when($stores !== null, fn ($q) => $q->whereIn('store_id', $stores)))
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->whereIn('status', ['approved', 'partial']) : $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%")))
            ->latest('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (StoreRequisition $r) => ['id' => $r->id, 'number' => $r->number, 'store' => $r->store?->name,
            'department' => $r->department?->name, 'purpose' => $r->purpose, 'items' => (int) $r->lines_count, 'status' => $r->status, 'status_label' => StoreRequisition::STATUS_LABELS[$r->status] ?? $r->status,
            'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'requested_by' => $r->requester?->display_name,
            'created_at' => $r->created_at->toIso8601String()])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * A store request with lines, stock available and issue notes.
     *
     * @response array{data: array{id: int, number: string, store_id: int, store: string|null, department_id: int, department: string|null, task_activity_id: int|null, activity: string|null, purpose: string|null, needed_by: string|null, status: string, status_label: string, requested_by: string|null, decision_comment: string|null, waiting_for: string|null, lines: list<array{id: int, item_id: int, item: string|null, code: string|null, unit_id: int|null, unit: string|null, quantity: float, issued_quantity: float, remaining: float, available: float, notes: string|null}>, issues: list<array{id: int, number: string, date: string, status: string, status_label: string, can_confirm: bool}>, can: array{edit: bool, submit: bool, withdraw: bool, cancel: bool, issue: bool}}}
     */
    public function show(Request $request, StoreRequisition $storeRequest): JsonResponse
    {
        $user = $request->user();
        $r = $storeRequest->load(['store', 'department', 'activity', 'lines.item', 'lines.unit', 'issues', 'requester', 'workflow.steps']);
        $approver = $r->workflow && ($r->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $r->workflow));
        $operates = $this->access->operates($user, $r->store_id);
        abort_unless($r->requested_by === $user->id || $operates || $approver || $this->access->can($user, 'view'), 403);
        $mine = $r->requested_by === $user->id;
        $stock = StockBalance::where('store_id', $r->store_id)->whereIn('item_id', $r->lines->pluck('item_id'))->pluck('quantity', 'item_id');

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'store_id' => $r->store_id, 'store' => $r->store?->name, 'department_id' => $r->department_id, 'department' => $r->department?->name,
            'task_activity_id' => $r->task_activity_id, 'activity' => $r->activity?->title, 'purpose' => $r->purpose, 'needed_by' => $r->needed_by?->toDateString(), 'status' => $r->status,
            'status_label' => StoreRequisition::STATUS_LABELS[$r->status] ?? $r->status, 'requested_by' => $r->requester?->display_name, 'decision_comment' => $r->decision_comment,
            'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'item' => $l->item?->name, 'code' => $l->item?->code, 'unit_id' => $l->unit_id, 'unit' => $l->unit?->code,
                'quantity' => $l->quantity, 'issued_quantity' => $l->issued_quantity, 'remaining' => $l->remaining(), 'available' => (float) ($stock[$l->item_id] ?? 0), 'notes' => $l->notes])->values(),
            'issues' => $r->issues->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'date' => $d->date->toDateString(), 'status' => $d->status,
                'status_label' => StockDocument::STATUS_LABELS[$d->status] ?? $d->status, 'can_confirm' => $d->status === 'awaiting' && $d->received_by_user_id === $user->id])->values(),
            'can' => ['edit' => $r->editable() && $mine, 'submit' => $r->editable() && $mine, 'withdraw' => $r->status === 'pending' && $mine,
                'cancel' => ($r->editable() && $mine) || (in_array($r->status, ['approved', 'partial'], true) && ($mine || $operates)), 'issue' => in_array($r->status, ['approved', 'partial'], true) && $operates],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(StoreRequisitionRequest $request): JsonResponse
    {
        abort_unless($this->access->canRequest($request->user(), (int) $request->validated('department_id')), 403, 'You can request items for your own department.');
        $r = $this->requisitions->save(null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(StoreRequisitionRequest $request, StoreRequisition $storeRequest): JsonResponse
    {
        abort_unless($storeRequest->requested_by === $request->user()->id && $this->access->canRequest($request->user(), (int) $request->validated('department_id')), 403);
        $r = $this->requisitions->save($storeRequest, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, StoreRequisition $storeRequest): JsonResponse
    {
        abort_unless($storeRequest->requested_by === $request->user()->id, 403);
        $r = $this->requisitions->submit($storeRequest, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, StoreRequisition $storeRequest): JsonResponse
    {
        $r = $this->requisitions->withdraw($storeRequest, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function cancel(Request $request, StoreRequisition $storeRequest): JsonResponse
    {
        $r = $this->requisitions->cancel($storeRequest->load('lines'), $request->user(), $this->access->operates($request->user(), $storeRequest->store_id));

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /**
     * Stores the user can ask from, own department and activities, for the request form.
     *
     * @response array{data: array{stores: list<array{id: int, name: string}>, department: array{id: int, name: string}|null, departments: list<array{id: int, name: string}>, activities: list<array{id: int, title: string, department_id: int}>}}
     */
    public function lookups(Request $request): JsonResponse
    {
        $user = $request->user();
        $own = $user->employee?->department;
        $all = $this->access->can($user, 'manage');

        return response()->json(['data' => [
            'stores' => \Modules\Inventory\Models\Store::where('is_active', true)->where('kind', '!=', 'transit')->orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'department' => $own ? ['id' => $own->id, 'name' => $own->name] : null,
            'departments' => \App\Models\Department::where('is_active', true)->when(! $all, fn ($q) => $q->whereKey($own?->id ?? 0))->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'activities' => \Modules\Tasks\Models\TaskActivity::where('is_active', true)->where('year', '>=', now()->year - 1)->when(! $all, fn ($q) => $q->where('department_id', $own?->id ?? 0))
                ->orderBy('title')->get()->map(fn ($a) => ['id' => $a->id, 'title' => trim(($a->code ? $a->code.' ' : '').$a->title), 'department_id' => (int) $a->department_id])->values(),
        ]]);
    }
}
