<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Support\PdfDocument;
use Modules\Procurement\Actions\ManageOrders;
use Modules\Procurement\Http\Requests\DirectOrderRequest;
use Modules\Procurement\Http\Requests\OrderUpdateRequest;
use Modules\Procurement\Http\Requests\ReasonRequest;
use Modules\Procurement\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Support\ProcurementAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Procurement: orders
 */
class OrderController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageOrders $orders) {}

    /** @response array{data: list<array{id: int, number: string, supplier: string|null, requisition: string|null, method_label: string, order_date: string, delivery_date: string|null, total: float, status: string, status_label: string, waiting_for: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}} */
    #[QueryParameter('search', description: 'Number or supplier.', type: 'string')]
    #[QueryParameter('status', description: 'A status, or `open` for approved and partly delivered.', type: 'string')]
    #[QueryParameter('sort', description: '`order_date`, `total`, `number`; prefix `-` for descending.', type: 'string', default: '-order_date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->sees($user) || $this->access->canReceive($user, null), 403);
        $sortParam = (string) $request->query('sort', '-order_date');
        $sort = in_array(ltrim($sortParam, '-'), ['order_date', 'total', 'number'], true) ? ltrim($sortParam, '-') : 'order_date';
        $page = PurchaseOrder::with(['supplier', 'requisition', 'workflow.steps'])->inAuditPeriod('order_date')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhereHas('supplier', fn ($x) => $x->where('name', 'like', "%{$s}%"))))
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->whereIn('status', ['approved', 'partial']) : $q->where('status', $s))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (PurchaseOrder $o) => ['id' => $o->id, 'number' => $o->number, 'supplier' => $o->supplier?->name,
            'requisition' => $o->requisition?->number, 'method_label' => PurchaseOrder::METHODS[$o->method] ?? $o->method, 'order_date' => $o->order_date->toDateString(),
            'delivery_date' => $o->delivery_date?->toDateString(), 'total' => (float) $o->total, 'status' => $o->status, 'status_label' => PurchaseOrder::STATUS_LABELS[$o->status] ?? $o->status,
            'waiting_for' => $o->status === 'pending' ? $o->workflow?->steps->firstWhere('status', 'pending')?->name : null])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * A purchase order with lines, deliveries and what the user can do.
     *
     * @response array{data: array{id: int, number: string, supplier: array{id: int, name: string, tin: string|null}, requisition: array{id: int, number: string, department: string|null}|null, store_id: int|null, store: string|null, method: string, method_label: string, method_reason: string|null, order_date: string, delivery_date: string|null, delivery_place: string|null, payment_terms_days: int|null, total: float, status: string, status_label: string, acknowledged_at: string|null, notes: string|null, decision_comment: string|null, waiting_for: string|null, lines: list<array{id: int, description: string, item_code: string|null, unit: string|null, quantity: float, unit_price: float, amount: float, received_quantity: float, due: float, track_expiry: bool, track_serial: bool}>, receipts: list<array{id: int, number: string, received_on: string, total: float, status: string, status_label: string}>, can: array{edit: bool, submit: bool, withdraw: bool, acknowledge: bool, receive: bool, cancel: bool}}}
     */
    public function show(Request $request, PurchaseOrder $order): JsonResponse
    {
        $user = $request->user();
        $o = $order->load(['supplier', 'requisition.department', 'store', 'lines.item', 'lines.unit', 'receipts', 'workflow.steps']);
        $approver = $o->workflow && ($o->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $o->workflow));
        abort_unless($this->access->sees($user) || $this->access->canReceive($user, $o->store_id) || $approver, 403);
        $buyer = $this->access->buyer($user);
        $draft = in_array($o->status, ['draft', 'rejected'], true);

        return response()->json(['data' => [
            'id' => $o->id, 'number' => $o->number, 'supplier' => ['id' => $o->supplier->id, 'name' => $o->supplier->name, 'tin' => $o->supplier->tin],
            'requisition' => $o->requisition ? ['id' => $o->requisition->id, 'number' => $o->requisition->number, 'department' => $o->requisition->department?->name] : null,
            'store_id' => $o->store_id, 'store' => $o->store?->name, 'method' => $o->method, 'method_label' => PurchaseOrder::METHODS[$o->method] ?? $o->method, 'method_reason' => $o->method_reason,
            'order_date' => $o->order_date->toDateString(), 'delivery_date' => $o->delivery_date?->toDateString(), 'delivery_place' => $o->delivery_place, 'payment_terms_days' => $o->payment_terms_days,
            'total' => (float) $o->total, 'status' => $o->status, 'status_label' => PurchaseOrder::STATUS_LABELS[$o->status] ?? $o->status, 'acknowledged_at' => $o->acknowledged_at?->toIso8601String(),
            'notes' => $o->notes, 'decision_comment' => $o->decision_comment, 'waiting_for' => $o->status === 'pending' ? $o->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'item_code' => $l->item?->code, 'unit' => $l->unit?->code, 'quantity' => $l->quantity,
                'unit_price' => $l->unit_price, 'amount' => $l->amount, 'received_quantity' => $l->received_quantity, 'due' => $l->due(),
                'track_expiry' => (bool) $l->item?->track_expiry, 'track_serial' => (bool) $l->item?->track_serial])->values(),
            'receipts' => $o->receipts->map(fn ($g) => ['id' => $g->id, 'number' => $g->number, 'received_on' => $g->received_on->toDateString(), 'total' => (float) $g->total,
                'status' => $g->status, 'status_label' => GoodsReceipt::STATUS_LABELS[$g->status] ?? $g->status])->values(),
            'can' => ['edit' => $buyer && $draft, 'submit' => $buyer && $draft, 'withdraw' => $o->status === 'pending' && $o->created_by === $user->id,
                'acknowledge' => $buyer && $o->receivable() && ! $o->acknowledged_at, 'receive' => $o->receivable() && $this->access->canReceive($user, $o->store_id),
                'cancel' => $buyer && ! in_array($o->status, ['pending', 'cancelled', 'closed', 'received'], true) && $o->receipts->whereIn('status', ['pending', 'accepted'])->isEmpty()],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(DirectOrderRequest $request, PurchaseRequisition $requisition): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $o = $this->orders->direct($requisition->load('lines'), $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(OrderUpdateRequest $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $o = $this->orders->update($order, $request->validated());

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $o = $this->orders->submit($order, $request->user());

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->orders->withdraw($order, $request->user());

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function acknowledge(Request $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $o = $this->orders->acknowledge($order);

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function cancel(ReasonRequest $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $o = $this->orders->cancel($order, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status]]);
    }

    /** The purchase order as a PDF to send to the supplier. */
    public function pdf(Request $request, PurchaseOrder $order, PdfDocument $pdf): Response
    {
        abort_unless($this->access->sees($request->user()), 403);
        $o = $order->load(['supplier', 'lines.unit']);
        $rows = $o->lines->map(fn ($l) => ['description' => $l->description, 'unit' => $l->unit?->code, 'quantity' => $l->quantity, 'unit_price' => number_format($l->unit_price, 2), 'amount' => number_format($l->amount, 2)])->all();
        $rows[] = ['description' => 'Total', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'amount' => number_format($o->total, 2)];

        return $pdf->keyed($request->user(), "Purchase order {$o->number}", "To {$o->supplier->name} (TIN {$o->supplier->tin}) · dated ".$o->order_date->format('j M Y').($o->delivery_date ? ' · deliver by '.$o->delivery_date->format('j M Y') : ''),
            [['key' => 'description', 'label' => 'Description'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'unit_price', 'label' => 'Unit price'], ['key' => 'amount', 'label' => 'Amount']], $rows);
    }
}
