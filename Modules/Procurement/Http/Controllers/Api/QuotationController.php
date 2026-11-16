<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Actions\ManageQuotations;
use Modules\Procurement\Http\Requests\AwardRequest;
use Modules\Procurement\Http\Requests\QuotationRecordRequest;
use Modules\Procurement\Http\Requests\QuotationRequestRequest;
use Modules\Procurement\Http\Requests\ReasonRequest;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Quotation;
use Modules\Procurement\Models\QuotationRequest;
use Modules\Procurement\Support\ProcurementAccess;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Procurement: quotations
 */
class QuotationController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageQuotations $quotations) {}

    /** @response array{data: list<array{id: int, number: string, title: string, requisition: string|null, closing_date: string, quotations: int, status: string, status_label: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}} */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $page = QuotationRequest::with('requisition')->withCount('quotations')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json(['data' => collect($page->items())->map(fn (QuotationRequest $q) => ['id' => $q->id, 'number' => $q->number, 'title' => $q->title,
            'requisition' => $q->requisition?->number, 'closing_date' => $q->closing_date->toDateString(), 'quotations' => (int) $q->quotations_count, 'status' => $q->status,
            'status_label' => QuotationRequest::STATUS_LABELS[$q->status] ?? $q->status])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * A request for quotations with the comparison: each requisition line against each supplier's price.
     *
     * @response array{data: array{id: int, number: string, title: string, closing_date: string, instructions: string|null, status: string, status_label: string, award_comment: string|null, waiting_for: string|null, min_quotes: int, requisition: array{id: int, number: string, title: string, department: string|null}, lines: list<array{id: int, description: string, unit: string|null, quantity: float, estimated_unit_cost: float}>, quotations: list<array{id: int, supplier_id: int, supplier: string|null, reference: string|null, valid_until: string|null, delivery_days: int|null, notes: string|null, responsive: bool, total: float, file_name: string|null, lines: list<array{purchase_requisition_line_id: int, unit_price: float, quantity: float, amount: float, awarded: bool, remarks: string|null}>}>, can: array{record: bool, award: bool, cancel: bool}}}
     */
    public function show(Request $request, QuotationRequest $rfq): JsonResponse
    {
        $user = $request->user();
        $q = $rfq->load(['requisition.department', 'requisition.lines.unit', 'quotations.supplier', 'quotations.lines', 'workflow.steps']);
        $approver = $q->workflow && ($q->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $q->workflow));
        abort_unless($this->access->sees($user) || $approver, 403);
        $buyer = $this->access->buyer($user);

        return response()->json(['data' => [
            'id' => $q->id, 'number' => $q->number, 'title' => $q->title, 'closing_date' => $q->closing_date->toDateString(), 'instructions' => $q->instructions, 'status' => $q->status,
            'status_label' => QuotationRequest::STATUS_LABELS[$q->status] ?? $q->status, 'award_comment' => $q->award_comment,
            'waiting_for' => $q->status === 'pending' ? $q->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'min_quotes' => (int) ProcurementSetting::current()->min_quotes,
            'requisition' => ['id' => $q->requisition->id, 'number' => $q->requisition->number, 'title' => $q->requisition->title, 'department' => $q->requisition->department?->name],
            'lines' => $q->requisition->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'unit' => $l->unit?->code, 'quantity' => $l->remaining() ?: $l->quantity,
                'estimated_unit_cost' => $l->estimated_unit_cost])->values(),
            'quotations' => $q->quotations->map(fn (Quotation $x) => ['id' => $x->id, 'supplier_id' => $x->supplier_id, 'supplier' => $x->supplier?->name, 'reference' => $x->reference,
                'valid_until' => $x->valid_until?->toDateString(), 'delivery_days' => $x->delivery_days, 'notes' => $x->notes, 'responsive' => (bool) $x->responsive, 'total' => (float) $x->total,
                'file_name' => $x->file_name, 'lines' => $x->lines->map(fn ($l) => ['purchase_requisition_line_id' => $l->purchase_requisition_line_id, 'unit_price' => $l->unit_price,
                    'quantity' => $l->quantity, 'amount' => $l->amount, 'awarded' => (bool) $l->awarded, 'remarks' => $l->remarks])->values()])->values(),
            'can' => ['record' => $buyer && $q->status === 'open', 'award' => $buyer && $q->status === 'open' && $q->quotations->isNotEmpty(), 'cancel' => $buyer && $q->status === 'open'],
        ]]);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function store(QuotationRequestRequest $request, PurchaseRequisition $requisition): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $q = $this->quotations->open($requisition, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $q->id, 'number' => $q->number]], 201);
    }

    /** @response array{data: array{id: int, total: float}} */
    public function record(QuotationRecordRequest $request, QuotationRequest $rfq): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $q = $this->quotations->record($rfq->load('requisition.lines'), collect($request->validated())->except('file')->all(), $request->file('file'));

        return response()->json(['data' => ['id' => $q->id, 'total' => (float) $q->total]], 201);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function award(AwardRequest $request, QuotationRequest $rfq): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $awards = collect($request->validated('awards'))->mapWithKeys(fn ($a) => [(int) $a['purchase_requisition_line_id'] => (int) $a['quotation_id']])->all();
        $q = $this->quotations->award($rfq, $awards, $request->validated('comment'), $request->user());

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status]]);
    }

    public function cancel(ReasonRequest $request, QuotationRequest $rfq): JsonResponse
    {
        abort_unless($this->access->buyer($request->user()), 403);
        $this->quotations->cancel($rfq, $request->validated('reason'));

        return response()->json(['message' => 'Request for quotations cancelled.']);
    }

    public function file(Request $request, QuotationRequest $rfq, Quotation $quotation): StreamedResponse
    {
        abort_unless($quotation->quotation_request_id === $rfq->id && $quotation->path && Storage::disk('local')->exists($quotation->path), 404);
        abort_unless($this->access->sees($request->user()), 403);

        return Storage::disk('local')->response($quotation->path, $quotation->file_name ?? 'quotation', ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
