<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageAgencies;
use Modules\Fleet\Models\AgencyBatch;
use Modules\Fleet\Models\AgencyContract;
use Modules\Fleet\Support\FleetAccess;
use Modules\Payments\Models\PaymentRequest;

/**
 * @tags Fleet: transport companies
 */
class AgencyController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageAgencies $agencies) {}

    /**
     * Transport company contracts and payment batches.
     *
     * @response array{data: list<array{id: int, supplier_id: int, supplier: string|null, reference: string|null, mode: string, mode_label: string, rate: float, currency: string, starts_on: string, ends_on: string, notes: string|null, is_active: bool, valid: bool, trips: int, unpaid: float}>, batches: list<array{id: int, number: string, contract: string, from_date: string, to_date: string, total: float, trips: int, payment_request: array{id: int, number: string, status: string, status_label: string}|null}>}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $contracts = AgencyContract::with('supplier')->withCount('trips')->withSum(['trips as unpaid' => fn ($q) => $q->where('status', 'confirmed')->whereNull('fleet_agency_batch_id')], 'agency_amount')
            ->orderByDesc('ends_on')->get();
        $batches = AgencyBatch::with(['contract.supplier', 'paymentRequest'])->withCount('trips')->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $contracts->map(fn ($c) => ['id' => $c->id, 'supplier_id' => $c->supplier_id, 'supplier' => $c->supplier?->name, 'reference' => $c->reference, 'mode' => $c->mode,
                'mode_label' => AgencyContract::MODES[$c->mode] ?? $c->mode, 'rate' => $c->rate, 'currency' => $c->currency, 'starts_on' => $c->starts_on->toDateString(),
                'ends_on' => $c->ends_on->toDateString(), 'notes' => $c->notes, 'is_active' => (bool) $c->is_active, 'valid' => $c->validOn(today()->toDateString()),
                'trips' => (int) $c->trips_count, 'unpaid' => round((float) $c->unpaid, 2)])->values(),
            'batches' => $batches->map(fn ($b) => ['id' => $b->id, 'number' => $b->number, 'contract' => $b->contract?->label(), 'from_date' => $b->from_date->toDateString(),
                'to_date' => $b->to_date->toDateString(), 'total' => $b->total, 'trips' => (int) $b->trips_count,
                'payment_request' => $b->paymentRequest ? ['id' => $b->paymentRequest->id, 'number' => $b->paymentRequest->number, 'status' => $b->paymentRequest->status,
                    'status_label' => PaymentRequest::STATUS_LABELS[$b->paymentRequest->status] ?? $b->paymentRequest->status] : null])->values(),
        ]);
    }

    /** @response array{data: array{id: int}} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $c = $this->agencies->save(null, $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:60'],
            /** trip, km or day. */
            'mode' => ['required', 'in:trip,km,day'],
            'rate' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]), $request->user());

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function update(Request $request, AgencyContract $contract): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $c = $this->agencies->save($contract, $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:60'],
            'mode' => ['required', 'in:trip,km,day'],
            'rate' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]), $request->user());

        return response()->json(['data' => ['id' => $c->id]]);
    }

    /**
     * Confirmed trips of a contract in a period not yet paid.
     *
     * @response array{data: list<array{id: int, number: string, purpose: string, ended_at: string|null, quantity: float|null, amount: float|null}>, total: float}
     */
    #[QueryParameter('from_date', type: 'string', required: true)]
    #[QueryParameter('to_date', type: 'string', required: true)]
    public function unbatched(Request $request, AgencyContract $contract): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $data = $request->validate(['from_date' => ['required', 'date'], 'to_date' => ['required', 'date']]);
        $trips = $this->agencies->unbatched($contract, $data['from_date'], $data['to_date']);

        return response()->json(['data' => $trips->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'purpose' => $t->purpose, 'ended_at' => $t->ended_at?->toIso8601String(),
            'quantity' => $t->agency_quantity, 'amount' => $t->agency_amount])->values(), 'total' => round($trips->sum('agency_amount'), 2)]);
    }

    /** @response array{data: array{id: int, number: string, payment_request_id: int|null}} */
    public function batch(Request $request, AgencyContract $contract): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            /** Department and budget line the payment request is charged to. */
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
        ]);
        $b = $this->agencies->batch($contract, $data, $request->user());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'payment_request_id' => $b->payment_request_id]], 201);
    }
}
