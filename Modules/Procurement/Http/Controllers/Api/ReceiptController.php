<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\ManageReceipts;
use Modules\Procurement\Http\Requests\GoodsReceiptRequest;
use Modules\Procurement\Http\Requests\SupplierInvoiceRequest;
use Modules\Procurement\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Support\ProcurementAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Procurement: goods received
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageReceipts $receipts) {}

    /** @response array{data: list<array{id: int, number: string, order: string|null, supplier: string|null, store: string|null, received_on: string, total: float, status: string, status_label: string, invoice: string|null, matched: bool}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}} */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('status', description: 'A status, or `unbilled` for accepted without a matching invoice.', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->sees($user) || $this->access->canReceive($user, null) || $this->access->canInvoice($user), 403);
        $page = GoodsReceipt::with(['order.supplier', 'store'])->inAuditPeriod('created_at')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('delivery_note', 'like', "%{$s}%")
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$s}%")->orWhereHas('supplier', fn ($x) => $x->where('name', 'like', "%{$s}%")))))
            ->when($request->query('status'), fn ($q, $s) => $s === 'unbilled' ? $q->where('status', 'accepted')->whereNull('supplier_invoice') : $q->where('status', $s))
            ->latest('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (GoodsReceipt $g) => ['id' => $g->id, 'number' => $g->number, 'order' => $g->order?->number,
            'supplier' => $g->order?->supplier?->name, 'store' => $g->store?->name, 'received_on' => $g->received_on->toDateString(), 'total' => (float) $g->total, 'status' => $g->status,
            'status_label' => GoodsReceipt::STATUS_LABELS[$g->status] ?? $g->status, 'invoice' => $g->supplier_invoice, 'matched' => $this->receipts->matches($g)])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * A goods received note with inspection results, journal, supplier invoice match and the bill.
     *
     * @response array{data: array{id: int, number: string, order: array{id: int, number: string, supplier: string|null}, store: string|null, delivery_note: string|null, received_on: string, notes: string|null, total: float, status: string, status_label: string, decision_comment: string|null, waiting_for: string|null, journal: array{id: int, reference_no: string|null}|null, supplier_invoice: string|null, invoice_date: string|null, invoice_amount: float|null, invoice_vat: float, matched: bool, payment_request: array{id: int, number: string, status_label: string}|null, lines: list<array{description: string|null, unit: string|null, ordered: float, unit_price: float, delivered_quantity: float, accepted_quantity: float, rejected_quantity: float, rejection_reason: string|null, batch_number: string|null, expiry_date: string|null, serials: list<string>|null, amount: float}>, can: array{submit: bool, withdraw: bool, cancel: bool, invoice: bool}}}
     */
    /** Goods received note as a PDF. */
    public function pdf(Request $request, GoodsReceipt $receipt, \Modules\Core\Support\PdfDocument $pdf): \Illuminate\Http\Response
    {
        $user = $request->user();
        $approver = $receipt->workflow && ($receipt->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $receipt->workflow));
        abort_unless($this->access->sees($user) || $this->access->canReceive($user, $receipt->store_id) || $this->access->canInvoice($user) || $approver, 403);

        return $pdf->view($user, 'pdf.procurement', "Goods received note {$receipt->number}", \Modules\Procurement\Support\ProcurementDocuments::receipt($receipt), 'landscape');
    }

    public function show(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        $g = $receipt->load(['order.supplier', 'store', 'lines.orderLine.unit', 'journal', 'paymentRequest', 'workflow.steps']);
        $approver = $g->workflow && ($g->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $g->workflow));
        abort_unless($this->access->sees($user) || $this->access->canReceive($user, $g->store_id) || $this->access->canInvoice($user) || $approver, 403);
        $mine = $g->created_by === $user->id;

        return response()->json(['data' => [
            'id' => $g->id, 'number' => $g->number, 'order' => ['id' => $g->order->id, 'number' => $g->order->number, 'supplier' => $g->order->supplier?->name], 'store' => $g->store?->name,
            'delivery_note' => $g->delivery_note, 'received_on' => $g->received_on->toDateString(), 'notes' => $g->notes, 'total' => (float) $g->total, 'status' => $g->status,
            'status_label' => GoodsReceipt::STATUS_LABELS[$g->status] ?? $g->status, 'decision_comment' => $g->decision_comment,
            'waiting_for' => $g->status === 'pending' ? $g->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'journal' => $g->journal ? ['id' => $g->journal->id, 'reference_no' => $g->journal->reference_no] : null,
            'supplier_invoice' => $g->supplier_invoice, 'invoice_date' => $g->invoice_date?->toDateString(), 'invoice_amount' => $g->invoice_amount, 'invoice_vat' => (float) $g->invoice_vat, 'matched' => $this->receipts->matches($g),
            'payment_request' => $g->paymentRequest ? ['id' => $g->paymentRequest->id, 'number' => $g->paymentRequest->number, 'status_label' => \Modules\Payments\Models\PaymentRequest::STATUS_LABELS[$g->paymentRequest->status] ?? $g->paymentRequest->status] : null,
            'lines' => $g->lines->map(fn ($l) => ['description' => $l->orderLine?->description, 'unit' => $l->orderLine?->unit?->code, 'ordered' => (float) $l->orderLine?->quantity,
                'unit_price' => (float) $l->orderLine?->unit_price, 'delivered_quantity' => $l->delivered_quantity, 'accepted_quantity' => $l->accepted_quantity, 'rejected_quantity' => $l->rejected_quantity,
                'rejection_reason' => $l->rejection_reason, 'batch_number' => $l->batch_number, 'expiry_date' => $l->expiry_date?->toDateString(), 'serials' => $l->serials, 'amount' => $l->amount])->values(),
            'can' => ['submit' => $mine && in_array($g->status, ['draft', 'rejected'], true), 'withdraw' => $mine && $g->status === 'pending', 'cancel' => $mine && in_array($g->status, ['draft', 'rejected'], true),
                'invoice' => $g->status === 'accepted' && $this->access->canInvoice($user)],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(GoodsReceiptRequest $request, PurchaseOrder $order): JsonResponse
    {
        abort_unless($this->access->canReceive($request->user(), $order->store_id), 403, 'Goods are received by the store staff of the delivery store.');
        $g = $this->receipts->create($order->load('lines.item'), $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $g->id, 'number' => $g->number, 'status' => $g->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        abort_unless($receipt->created_by === $request->user()->id, 403);
        $g = $this->receipts->submit($receipt, $request->user());

        return response()->json(['data' => ['id' => $g->id, 'number' => $g->number, 'status' => $g->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        $g = $this->receipts->withdraw($receipt, $request->user());

        return response()->json(['data' => ['id' => $g->id, 'number' => $g->number, 'status' => $g->status]]);
    }

    public function cancel(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        abort_unless($receipt->created_by === $request->user()->id, 403);
        $this->receipts->cancel($receipt);

        return response()->json(['message' => 'Delivery cancelled.']);
    }

    /** @response array{data: array{id: int, matched: bool}} */
    public function invoice(SupplierInvoiceRequest $request, GoodsReceipt $receipt): JsonResponse
    {
        abort_unless($this->access->canInvoice($request->user()), 403);
        $g = $this->receipts->invoice($receipt, $request->validated());

        return response()->json(['data' => ['id' => $g->id, 'matched' => $this->receipts->matches($g)]]);
    }
}
