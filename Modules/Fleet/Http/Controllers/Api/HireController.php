<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageHires;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Hire;
use Modules\Fleet\Support\FleetAccess;
use Modules\Payments\Models\PaymentRequest;

/**
 * @tags Fleet: hired vehicles
 */
class HireController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageHires $hires) {}

    /**
     * Vehicle hires with their vehicles and payments.
     *
     * @response array{data: list<array{id: int, number: string, supplier: string|null, reference: string|null, vehicle_type: string|null, starts_on: string, ends_on: string, ended_on: string|null, end_note: string|null, rate_mode: string, rate_label: string, rate: float, currency: string, notes: string|null, status: string, status_label: string, vehicles: list<array{id: int, label: string, status_label: string}>, paid_until: string|null, paid: float, payments: list<array{from_date: string, to_date: string, quantity: float, amount: float, payment_request: array{id: int, number: string, status_label: string}|null}>}>}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $hires = Hire::with(['supplier', 'type', 'units', 'payments.paymentRequest'])->orderByDesc('starts_on')->orderByDesc('id')->limit(300)->get();

        return response()->json(['data' => $hires->map(fn (Hire $h) => [
            'id' => $h->id, 'number' => $h->number, 'supplier' => $h->supplier?->name, 'reference' => $h->reference, 'vehicle_type' => $h->type?->name,
            'starts_on' => $h->starts_on->toDateString(), 'ends_on' => $h->ends_on->toDateString(), 'ended_on' => $h->ended_on?->toDateString(), 'end_note' => $h->end_note,
            'rate_mode' => $h->rate_mode, 'rate_label' => Hire::MODES[$h->rate_mode] ?? $h->rate_mode, 'rate' => $h->rate, 'currency' => $h->currency, 'notes' => $h->notes,
            'status' => $h->status, 'status_label' => Hire::STATUS_LABELS[$h->status] ?? $h->status,
            'vehicles' => $h->units->map(fn ($u) => ['id' => $u->id, 'label' => $u->label(), 'status_label' => FleetUnit::STATUSES[$u->status] ?? $u->status])->values(),
            'paid_until' => $h->payments->max(fn ($p) => $p->to_date->toDateString()), 'paid' => round($h->payments->sum('amount'), 2),
            'payments' => $h->payments->map(fn ($p) => ['from_date' => $p->from_date->toDateString(), 'to_date' => $p->to_date->toDateString(), 'quantity' => $p->quantity, 'amount' => $p->amount,
                'payment_request' => $p->paymentRequest ? ['id' => $p->paymentRequest->id, 'number' => $p->paymentRequest->number,
                    'status_label' => PaymentRequest::STATUS_LABELS[$p->paymentRequest->status] ?? $p->paymentRequest->status] : null])->values(),
        ])->values()]);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:60'],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:fleet_vehicle_types,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            /** day or month (per vehicle). */
            'rate_mode' => ['required', 'in:day,month'],
            'rate' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
            'vehicles' => ['required', 'array', 'min:1', 'max:100'],
            'vehicles.*.registration' => ['required', 'string', 'max:30'],
            'vehicles.*.name' => ['required', 'string', 'max:150'],
            'vehicles.*.make' => ['nullable', 'string', 'max:60'],
            'vehicles.*.model' => ['nullable', 'string', 'max:60'],
            'vehicles.*.seats' => ['nullable', 'integer', 'min:1', 'max:200'],
            'vehicles.*.fuel_type' => ['nullable', 'in:petrol,diesel,electric,hybrid,gas'],
            'vehicles.*.reading' => ['nullable', 'numeric', 'min:0'],
            'vehicles.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $h = $this->hires->create($data, $request->user());

        return response()->json(['data' => ['id' => $h->id, 'number' => $h->number]], 201);
    }

    /** @response array{message: string} */
    public function addVehicle(Request $request, Hire $hire): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'registration' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:200'],
            'fuel_type' => ['nullable', 'in:petrol,diesel,electric,hybrid,gas'],
            'reading' => ['nullable', 'numeric', 'min:0'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $this->hires->addVehicle($hire, $data, $request->user());

        return response()->json(['message' => 'Vehicle added.']);
    }

    /** @response array{message: string} */
    public function end(Request $request, Hire $hire): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->hires->end($hire, $data['date'], $data['note'] ?? null, $request->user());

        return response()->json(['message' => 'Hire ended; its vehicles have left the fleet.']);
    }

    /** @response array{data: array{payment_request_id: int|null, amount: float}} */
    public function pay(Request $request, Hire $hire): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
        ]);
        $p = $this->hires->pay($hire, $data, $request->user());

        return response()->json(['data' => ['payment_request_id' => $p->payment_request_id, 'amount' => $p->amount]], 201);
    }
}
