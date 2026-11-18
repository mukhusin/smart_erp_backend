<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Actions\ManageStockDocuments;
use Modules\Inventory\Http\Requests\CountLinesRequest;
use Modules\Inventory\Http\Requests\ReasonRequest;
use Modules\Inventory\Http\Requests\ReceiveTransferRequest;
use Modules\Inventory\Http\Requests\StockDocumentRequest;
use Modules\Inventory\Models\StockDocument;
use Modules\Inventory\Support\InventoryAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Inventory: stock documents
 */
class StockDocumentController extends Controller
{
    public function __construct(private readonly InventoryAccess $access, private readonly ManageStockDocuments $documents) {}

    /** @response array{data: list<array{id: int, number: string, kind: string, kind_label: string, reason_label: string|null, store: string|null, to_store: string|null, department: string|null, date: string, value: float, status: string, status_label: string, waiting_for: string|null, created_by: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}} */
    #[QueryParameter('kind', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('store_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesStock($request->user()), 403);
        $stores = $this->access->stores($request->user());
        $page = StockDocument::with(['store', 'toStore', 'department', 'creator', 'workflow.steps'])->inAuditPeriod('date')
            ->when($stores !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('store_id', $stores)->orWhereIn('to_store_id', $stores)))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('store_id'), fn ($q, $s) => $q->where(fn ($q) => $q->where('store_id', $s)->orWhere('to_store_id', $s)))
            ->when($request->query('search'), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->latest('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (StockDocument $d) => ['id' => $d->id, 'number' => $d->number, 'kind' => $d->kind, 'kind_label' => StockDocument::KINDS[$d->kind] ?? $d->kind,
            'reason_label' => $d->reason ? (StockDocument::REASONS[$d->reason] ?? $d->reason) : null, 'store' => $d->store?->name, 'to_store' => $d->toStore?->name, 'department' => $d->department?->name,
            'date' => $d->date->toDateString(), 'value' => (float) $d->value, 'status' => $d->status, 'status_label' => StockDocument::STATUS_LABELS[$d->status] ?? $d->status,
            'waiting_for' => $d->status === 'pending' ? $d->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'created_by' => $d->creator?->display_name])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /** @response array{data: array{id: int, number: string, kind: string, kind_label: string, reason: string|null, reason_label: string|null, store_id: int, store: string|null, to_store_id: int|null, to_store: string|null, requisition: array{id: int, number: string}|null, department: string|null, activity: string|null, received_by: string|null, date: string, notes: string|null, value: float, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, journal: array{id: int, reference_no: string|null}|null, created_by: string|null, lines: list<array{id: int, item_id: int, item: string|null, code: string|null, unit: string|null, quantity: float, base_quantity: float, system_quantity: float|null, received_quantity: float|null, unit_cost: float, value: float, batch_number: string|null, expiry_date: string|null, reason: string|null}>, can: array{edit_counts: bool, submit: bool, withdraw: bool, cancel: bool, confirm: bool, receive: bool, reverse: bool}}} */
    public function show(Request $request, StockDocument $document): JsonResponse
    {
        $user = $request->user();
        $d = $document->load(['store', 'toStore', 'requisition', 'department', 'activity', 'receiver', 'journal', 'creator', 'lines.item', 'lines.unit', 'workflow.steps']);
        $approver = $d->workflow && ($d->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $d->workflow));
        $operates = $this->access->operates($user, $d->store_id);
        $receiving = $d->to_store_id && $this->access->operates($user, $d->to_store_id);
        abort_unless($operates || $receiving || $approver || $d->received_by_user_id === $user->id || $this->access->can($user, 'view'), 403);
        $mine = $d->created_by === $user->id;

        return response()->json(['data' => [
            'id' => $d->id, 'number' => $d->number, 'kind' => $d->kind, 'kind_label' => StockDocument::KINDS[$d->kind] ?? $d->kind, 'reason' => $d->reason,
            'reason_label' => $d->reason ? (StockDocument::REASONS[$d->reason] ?? $d->reason) : null, 'store_id' => $d->store_id, 'store' => $d->store?->name, 'to_store_id' => $d->to_store_id,
            'to_store' => $d->toStore?->name, 'requisition' => $d->requisition ? ['id' => $d->requisition->id, 'number' => $d->requisition->number] : null, 'department' => $d->department?->name,
            'activity' => $d->activity?->title, 'received_by' => $d->receiver?->display_name, 'date' => $d->date->toDateString(), 'notes' => $d->notes, 'value' => (float) $d->value, 'status' => $d->status,
            'status_label' => StockDocument::STATUS_LABELS[$d->status] ?? $d->status, 'waiting_for' => $d->status === 'pending' ? $d->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'decision_comment' => $d->decision_comment, 'journal' => $d->journal ? ['id' => $d->journal->id, 'reference_no' => $d->journal->reference_no] : null, 'created_by' => $d->creator?->display_name,
            'lines' => $d->lines->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'item' => $l->item?->name, 'code' => $l->item?->code, 'unit' => $l->unit?->code, 'quantity' => $l->quantity,
                'base_quantity' => $l->base_quantity, 'system_quantity' => $l->system_quantity, 'received_quantity' => $l->received_quantity, 'unit_cost' => $l->unit_cost, 'value' => $l->value,
                'batch_number' => $l->batch_number, 'expiry_date' => $l->expiry_date?->toDateString(), 'reason' => $l->reason])->values(),
            'can' => ['edit_counts' => $d->kind === 'count' && in_array($d->status, ['counting', 'rejected'], true) && $operates, 'submit' => $mine && in_array($d->status, ['draft', 'rejected', 'counting'], true),
                'withdraw' => $mine && $d->status === 'pending', 'cancel' => $mine && in_array($d->status, ['draft', 'rejected', 'counting'], true),
                'confirm' => $d->status === 'awaiting' && $d->received_by_user_id === $user->id, 'receive' => $d->kind === 'transfer' && $d->status === 'transit' && ($receiving || $this->access->can($user, 'manage')),
                'reverse' => in_array($d->kind, ['issue', 'receipt'], true) && in_array($d->status, ['posted', 'awaiting', 'received'], true) && $this->access->can($user, 'manage')],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(StockDocumentRequest $request): JsonResponse
    {
        abort_unless($this->access->operates($request->user(), (int) $request->validated('store_id')), 403, 'Only the staff of this store can prepare its documents.');
        $d = $this->documents->create($request->validated('kind'), $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function counts(CountLinesRequest $request, StockDocument $document): JsonResponse
    {
        abort_unless($this->access->operates($request->user(), $document->store_id), 403);
        $d = $this->documents->saveCounts($document->load('lines'), $request->validated('counts'));

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, StockDocument $document): JsonResponse
    {
        abort_unless($document->created_by === $request->user()->id, 403);
        $d = $this->documents->submit($document->load('lines'), $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, StockDocument $document): JsonResponse
    {
        $d = $this->documents->withdraw($document, $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    public function cancel(Request $request, StockDocument $document): JsonResponse
    {
        abort_unless($document->created_by === $request->user()->id, 403);
        $this->documents->cancel($document);

        return response()->json(['message' => 'Document cancelled.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function confirm(Request $request, StockDocument $document): JsonResponse
    {
        $d = $this->documents->confirm($document, $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function receive(ReceiveTransferRequest $request, StockDocument $document): JsonResponse
    {
        abort_unless(($document->to_store_id && $this->access->operates($request->user(), $document->to_store_id)) || $this->access->can($request->user(), 'manage'), 403);
        $d = $this->documents->receiveTransfer($document, $request->validated('lines'), $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function reverse(ReasonRequest $request, StockDocument $document): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $d = $this->documents->reverse($document, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }
}
