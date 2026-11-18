<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Receivables\Actions\ManageClaims;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\InsuranceClaim;
use Modules\Receivables\Models\InsuranceClaimLine;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: insurance claims
 */
class ClaimController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access, private readonly ManageClaims $claims) {}

    /**
     * Claims to insurers and other third-party payers.
     *
     * @response array{data: list<array{id: int, number: string, customer_id: int, customer: string|null, period_from: string, period_to: string, status: string, status_label: string, claimed: float, approved: float, rejected: float, outstanding: float, submitted_on: string|null, days_waiting: int|null, lines: int}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}, payers: list<array{id: int, name: string, kind: string}>}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('customer_id', type: 'integer')]
    #[QueryParameter('sort', description: '`period_to`, `claimed`, `number`, `submitted_on`; prefix `-` for descending.', type: 'string', default: '-period_to')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', '-period_to');
        $sort = in_array(ltrim($sortParam, '-'), ['period_to', 'claimed', 'number', 'submitted_on'], true) ? ltrim($sortParam, '-') : 'period_to';
        $page = InsuranceClaim::with(['customer', 'lines.invoice'])->withCount('lines')->inAuditPeriod('period_to')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('payer_reference', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));
        foreach ($page->items() as $c) {
            $this->claims->refresh($c);
        }

        return response()->json([
            'data' => collect($page->items())->map(fn (InsuranceClaim $c) => ['id' => $c->id, 'number' => $c->number, 'customer_id' => $c->customer_id, 'customer' => $c->customer?->name,
                'period_from' => $c->period_from->toDateString(), 'period_to' => $c->period_to->toDateString(), 'status' => $c->status, 'status_label' => InsuranceClaim::STATUS_LABELS[$c->status] ?? $c->status,
                'claimed' => (float) $c->claimed, 'approved' => (float) $c->approved, 'rejected' => (float) $c->rejected,
                'outstanding' => in_array($c->status, ['submitted', 'responded'], true) ? $this->claims->outstanding($c) : 0.0, 'submitted_on' => $c->submitted_on?->toDateString(),
                'days_waiting' => in_array($c->status, ['submitted', 'responded'], true) && $c->submitted_on ? (int) $c->submitted_on->diffInDays(today()) : null, 'lines' => (int) $c->lines_count])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'payers' => Customer::where('is_active', true)->whereIn('kind', ['insurer', 'company', 'donor', 'other'])->orderBy('name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind])->values(),
        ]);
    }

    /**
     * A claim with its invoices, the payer's response and what is left to handle.
     *
     * @response array{data: array{id: int, number: string, customer: array{id: int, name: string}, period_from: string, period_to: string, status: string, status_label: string, payer_reference: string|null, notes: string|null, claimed: float, approved: float, rejected: float, outstanding: float, submitted_on: string|null, responded_on: string|null, created_by: string|null, lines: list<array{id: int, invoice_id: int, invoice: string, date: string, beneficiary: string|null, member_no: string|null, claimed: float, approved: float|null, rejected: float, rejection_reason: string|null, invoice_balance: float, resolution: string|null, resolution_label: string|null, credit_note: string|null, rebill_invoice_id: int|null, rebill_invoice: string|null}>, candidates: list<array{id: int, number: string, date: string, beneficiary: string|null, member_no: string|null, balance: float}>, can: array{edit: bool, submit: bool, respond: bool, resolve: bool, cancel: bool}}}
     */
    public function show(Request $request, InsuranceClaim $claim): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $this->claims->refresh($claim);
        $c = $claim->load(['customer', 'creator', 'lines.invoice', 'lines.creditNote', 'lines.rebillInvoice']);
        $bill = $this->access->can($request->user(), 'bill');

        return response()->json(['data' => [
            'id' => $c->id, 'number' => $c->number, 'customer' => ['id' => $c->customer->id, 'name' => $c->customer->name], 'period_from' => $c->period_from->toDateString(),
            'period_to' => $c->period_to->toDateString(), 'status' => $c->status, 'status_label' => InsuranceClaim::STATUS_LABELS[$c->status] ?? $c->status, 'payer_reference' => $c->payer_reference,
            'notes' => $c->notes, 'claimed' => (float) $c->claimed, 'approved' => (float) $c->approved, 'rejected' => (float) $c->rejected, 'outstanding' => $this->claims->outstanding($c),
            'submitted_on' => $c->submitted_on?->toDateString(), 'responded_on' => $c->responded_on?->toDateString(), 'created_by' => $c->creator?->display_name,
            'lines' => $c->lines->map(fn (InsuranceClaimLine $l) => ['id' => $l->id, 'invoice_id' => $l->invoice_id, 'invoice' => $l->invoice->number, 'date' => $l->invoice->date->toDateString(),
                'beneficiary' => $l->invoice->beneficiary, 'member_no' => $l->invoice->member_no, 'claimed' => $l->claimed, 'approved' => $l->approved, 'rejected' => $l->rejected,
                'rejection_reason' => $l->rejection_reason, 'invoice_balance' => $l->invoice->balance(), 'resolution' => $l->resolution,
                'resolution_label' => $l->resolution ? (InsuranceClaimLine::RESOLUTIONS[$l->resolution] ?? $l->resolution) : null,
                'credit_note' => $l->creditNote ? "{$l->creditNote->number} (".$l->creditNote->status.')' : null, 'rebill_invoice_id' => $l->rebill_invoice_id, 'rebill_invoice' => $l->rebillInvoice?->number])->values(),
            'candidates' => $c->status === 'draft' && $bill ? $this->claims->candidates($c->customer_id, $c->period_from->toDateString(), $c->period_to->toDateString(), $c->id)->orderBy('date')->get()
                ->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'date' => $i->date->toDateString(), 'beneficiary' => $i->beneficiary, 'member_no' => $i->member_no, 'balance' => $i->balance()])->values() : [],
            'can' => ['edit' => $bill && $c->status === 'draft', 'submit' => $bill && $c->status === 'draft', 'respond' => $bill && $c->status === 'submitted',
                'resolve' => $bill && $c->status === 'responded', 'cancel' => $bill && in_array($c->status, ['draft', 'submitted'], true)],
        ]]);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $c = $this->claims->create($data, $request->user());

        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number]], 201);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function update(Request $request, InsuranceClaim $claim): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $data = $request->validate(['invoice_ids' => ['present', 'array', 'max:5000'], 'invoice_ids.*' => ['integer']]);
        $c = $this->claims->setInvoices($claim, $data['invoice_ids']);

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function submit(Request $request, InsuranceClaim $claim): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $data = $request->validate(['submitted_on' => ['required', 'date', 'before_or_equal:today'], 'payer_reference' => ['nullable', 'string', 'max:100']]);
        $c = $this->claims->submit($claim, $data);

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function respond(Request $request, InsuranceClaim $claim): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $data = $request->validate([
            'responded_on' => ['required', 'date', 'before_or_equal:today'],
            'lines' => ['required', 'array'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.approved' => ['required', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ]);
        $c = $this->claims->respond($claim->load('lines'), $data);

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status]]);
    }

    /** @response array{data: array{id: int, resolution: string|null}} */
    public function resolve(Request $request, InsuranceClaim $claim, InsuranceClaimLine $line): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill') && $line->insurance_claim_id === $claim->id, 403);
        $data = $request->validate([
            /** written_off (credit note), rebilled (credit note + draft invoice to the patient / member) or resubmit (claim it again later). */
            'action' => ['required', 'in:written_off,rebilled,resubmit'],
            'date' => ['required', 'date'],
            'customer_id' => ['nullable', 'integer'],
        ]);
        $l = $this->claims->resolve($line, $data, $request->user());

        return response()->json(['data' => ['id' => $l->id, 'resolution' => $l->resolution]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function cancel(Request $request, InsuranceClaim $claim): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $c = $this->claims->cancel($claim);

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status]]);
    }
}
