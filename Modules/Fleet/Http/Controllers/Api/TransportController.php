<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageDrivers;
use Modules\Fleet\Actions\ManageTransport;
use Modules\Fleet\Models\AgencyContract;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\TransportRequest;
use Modules\Fleet\Models\Trip;
use Modules\Fleet\Support\FleetAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Fleet: transport
 */
class TransportController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageTransport $transport) {}

    /**
     * Transport requests: all for the fleet office, own ones otherwise (`mine`).
     *
     * @response array{data: list<array{id: int, number: string, purpose: string, destination: string, departs_at: string, returns_at: string, passengers: int, department: string|null, requested_by: string|null, status: string, status_label: string, waiting_for: string|null, trip: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('mine', type: 'boolean')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('search', description: 'Number, purpose or destination.', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function requests(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = $request->boolean('mine') || ! $this->access->sees($user);
        $page = TransportRequest::with(['department', 'requester', 'workflow.steps', 'trips.unit', 'trips.contract.supplier'])
            ->when($mine, fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%")->orWhere('destination', 'like', "%{$s}%")))
            ->orderByDesc('departs_at')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (TransportRequest $r) => ['id' => $r->id, 'number' => $r->number, 'purpose' => $r->purpose, 'destination' => $r->destination,
                'departs_at' => $r->departs_at->toIso8601String(), 'returns_at' => $r->returns_at->toIso8601String(), 'passengers' => $r->passengers, 'department' => $r->department?->name,
                'requested_by' => $r->requester?->display_name, 'status' => $r->status, 'status_label' => TransportRequest::STATUS_LABELS[$r->status] ?? $r->status,
                'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'trip' => $r->trips->whereNotIn('status', ['cancelled'])->map(fn ($t) => $t->unit?->label() ?? $t->contract?->label())->filter()->join(', ') ?: null])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A transport request with its trips.
     *
     * @response array{data: array{id: int, number: string, purpose: string, from_location: string|null, destination: string, departs_at: string, returns_at: string, passengers: int, passenger_names: string|null, cargo: string|null, cargo_weight: float|null, vehicle_type_id: int|null, vehicle_type: string|null, department_id: int|null, department: string|null, requested_by: string|null, status: string, status_label: string, decision_comment: string|null, waiting_for: string|null, trips: list<array{id: int, number: string, vehicle: string|null, driver: string|null, driver_phone: string|null, company: string|null, departs_at: string, returns_at: string, status: string, status_label: string}>, can: array{withdraw: bool, cancel: bool, allocate: bool, give_back: bool}}}
     */
    public function showRequest(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        $r = $transportRequest->load(['department', 'requester', 'type', 'workflow.steps', 'trips.unit', 'trips.driver.employee', 'trips.contract.supplier']);
        $user = $request->user();
        $this->authorizeRequest($user, $r);
        $manage = $this->access->manages($user);
        $mine = $r->requested_by === $user->id;

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'purpose' => $r->purpose, 'from_location' => $r->from_location, 'destination' => $r->destination,
            'departs_at' => $r->departs_at->toIso8601String(), 'returns_at' => $r->returns_at->toIso8601String(), 'passengers' => $r->passengers, 'passenger_names' => $r->passenger_names,
            'cargo' => $r->cargo, 'cargo_weight' => $r->cargo_weight, 'vehicle_type_id' => $r->vehicle_type_id, 'vehicle_type' => $r->type?->name, 'department_id' => $r->department_id,
            'department' => $r->department?->name, 'requested_by' => $r->requester?->display_name, 'status' => $r->status, 'status_label' => TransportRequest::STATUS_LABELS[$r->status] ?? $r->status,
            'decision_comment' => $r->decision_comment, 'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'trips' => $r->trips->map(fn (Trip $t) => ['id' => $t->id, 'number' => $t->number, 'vehicle' => $t->unit?->label(), 'driver' => $t->driver?->name(), 'driver_phone' => $t->driver?->employee?->phone_number,
                'company' => $t->contract?->label(), 'departs_at' => $t->departs_at->toIso8601String(), 'returns_at' => $t->returns_at->toIso8601String(), 'status' => $t->status,
                'status_label' => Trip::STATUS_LABELS[$t->status] ?? $t->status])->values(),
            'can' => ['withdraw' => $mine && $r->status === 'pending', 'cancel' => ($mine || $manage) && in_array($r->status, ['approved', 'allocated'], true),
                'allocate' => $manage && $r->status === 'approved', 'give_back' => $manage && $r->status === 'approved'],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:255'],
            'from_location' => ['nullable', 'string', 'max:150'],
            'destination' => ['required', 'string', 'max:255'],
            'departs_at' => ['required', 'date'],
            'returns_at' => ['required', 'date'],
            'passengers' => ['required', 'integer', 'min:0', 'max:200'],
            'passenger_names' => ['nullable', 'string', 'max:2000'],
            'cargo' => ['nullable', 'string', 'max:255'],
            /** Weight of the goods in kg. */
            'cargo_weight' => ['nullable', 'numeric', 'min:0'],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:fleet_vehicle_types,id'],
            /** Defaults to the requester's department. */
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        abort_unless($request->user()->employee || $this->access->manages($request->user()), 403, 'Only staff registered in HR can ask for transport.');
        $r = $this->transport->request($data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{message: string} */
    public function withdrawRequest(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        $this->transport->withdraw($transportRequest, $request->user());

        return response()->json(['message' => 'Request withdrawn.']);
    }

    /** @response array{message: string} */
    public function cancelRequest(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($transportRequest->requested_by === $request->user()->id || $this->access->manages($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->transport->cancel($transportRequest, $data['reason'], $request->user());

        return response()->json(['message' => 'Request cancelled.']);
    }

    /** @response array{message: string} */
    public function giveBack(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->transport->giveBack($transportRequest, $data['reason']);

        return response()->json(['message' => 'Request returned to the requester.']);
    }

    /**
     * Which vehicles, drivers and transport contracts are free for a period.
     *
     * @response array{data: array{vehicles: list<array{id: int, label: string, type: string|null, seats: int|null, free: bool, note: string|null}>, drivers: list<array{id: int, name: string, free: bool, note: string|null}>, contracts: list<array{id: int, label: string, mode: string, rate: float}>}}
     */
    #[QueryParameter('departs_at', type: 'string', required: true)]
    #[QueryParameter('returns_at', type: 'string', required: true)]
    #[QueryParameter('except_trip_id', type: 'integer')]
    public function availability(Request $request, ManageDrivers $drivers): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['departs_at' => ['required', 'date'], 'returns_at' => ['required', 'date', 'after:departs_at'], 'except_trip_id' => ['nullable', 'integer']]);
        $busy = Trip::with(['unit', 'driver'])->whereIn('status', Trip::BUSY)->where('departs_at', '<', $data['returns_at'])->where('returns_at', '>', $data['departs_at'])
            ->when($data['except_trip_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->get();
        $leave = EmpLeave::whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])->whereDate('start_date', '<=', $data['returns_at'])->whereDate('end_date', '>=', $data['departs_at'])
            ->pluck('employee_id')->flip();
        $vehicles = FleetUnit::with('type')->where('kind', 'vehicle')->where('status', 'operational')->orderBy('registration')->get();
        $date = substr($data['departs_at'], 0, 10);

        return response()->json(['data' => [
            'vehicles' => $vehicles->map(function ($u) use ($busy) {
                $trip = $busy->firstWhere('fleet_unit_id', $u->id);

                return ['id' => $u->id, 'label' => $u->label(), 'type' => $u->type?->name, 'seats' => $u->seats, 'free' => ! $trip, 'note' => $trip ? "On trip {$trip->number}" : null];
            })->values(),
            'drivers' => Driver::with(['employee', 'licence'])->where('status', 'active')->get()->sortBy(fn ($d) => $d->name())->map(function ($d) use ($busy, $leave) {
                $trip = $busy->firstWhere('fleet_driver_id', $d->id);
                $note = $trip ? "On trip {$trip->number}" : ($leave->has($d->employee_id) ? 'On leave' : (! $d->licence?->valid() ? 'No valid licence' : null));

                return ['id' => $d->id, 'name' => $d->name(), 'free' => ! $note, 'note' => $note];
            })->values(),
            'contracts' => AgencyContract::with('supplier')->where('is_active', true)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->get()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label(), 'mode' => $c->mode, 'rate' => $c->rate])->values(),
        ]]);
    }

    /**
     * Trips: scheduled, on the road and done.
     *
     * @response array{data: list<array{id: int, number: string, purpose: string, destination: string, departs_at: string, returns_at: string, vehicle: string|null, driver: string|null, company: string|null, request: string|null, status: string, status_label: string, distance: float|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('status', description: 'A status, or `open` for scheduled, on the road and waiting for confirmation.', type: 'string')]
    #[QueryParameter('unit_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function trips(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $status = (string) $request->query('status');
        $page = Trip::with(['unit', 'driver.employee', 'contract.supplier', 'request'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['scheduled', 'started', 'completed']), fn ($q) => $q->when($status, fn ($q) => $q->where('status', $status)))
            ->when($request->query('unit_id'), fn ($q, $u) => $q->where('fleet_unit_id', $u))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%")->orWhere('destination', 'like', "%{$s}%")))
            ->orderByDesc('departs_at')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Trip $t) => $this->tripRow($t))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A trip with its logbook.
     *
     * @response array{data: array{id: int, number: string, purpose: string, from_location: string|null, destination: string, departs_at: string, returns_at: string, passengers: int, cargo: string|null, cargo_weight: float|null, vehicle_id: int|null, vehicle: string|null, unit_reading: float|null, driver_id: int|null, driver: string|null, company: string|null, mode: string|null, rate: float|null, request: array{id: int, number: string, requested_by: string|null}|null, status: string, status_label: string, override_reason: string|null, start_reading: float|null, started_at: string|null, end_reading: float|null, ended_at: string|null, distance: float|null, logbook_notes: string|null, confirmed_by: string|null, confirmed_at: string|null, agency_quantity: float|null, agency_amount: float|null, batch: string|null, cancel_reason: string|null, can: array{reschedule: bool, cancel: bool, start: bool, finish: bool, confirm: bool}}}
     */
    public function showTrip(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();
        $trip->load(['unit', 'driver.employee', 'contract.supplier', 'request.requester', 'confirmer']);
        $driver = $this->isDriver($user, $trip);
        abort_unless($this->access->sees($user) || $driver || $trip->request?->requested_by === $user->id, 403);
        $manage = $this->access->manages($user);

        return response()->json(['data' => $this->tripRow($trip) + [
            'from_location' => $trip->from_location, 'passengers' => $trip->passengers, 'cargo' => $trip->cargo, 'cargo_weight' => $trip->cargo_weight, 'vehicle_id' => $trip->fleet_unit_id,
            'unit_reading' => $trip->unit?->reading, 'driver_id' => $trip->fleet_driver_id, 'mode' => $trip->contract?->mode, 'rate' => $trip->contract?->rate,
            'request' => $trip->request ? ['id' => $trip->request->id, 'number' => $trip->request->number, 'requested_by' => $trip->request->requester?->display_name] : null,
            'override_reason' => $trip->override_reason, 'start_reading' => $trip->start_reading, 'started_at' => $trip->started_at?->toIso8601String(), 'end_reading' => $trip->end_reading,
            'ended_at' => $trip->ended_at?->toIso8601String(), 'logbook_notes' => $trip->logbook_notes, 'confirmed_by' => $trip->confirmer?->display_name,
            'confirmed_at' => $trip->confirmed_at?->toIso8601String(), 'agency_quantity' => $trip->agency_quantity, 'agency_amount' => $trip->agency_amount,
            'batch' => $trip->fleet_agency_batch_id ? \Modules\Fleet\Models\AgencyBatch::find($trip->fleet_agency_batch_id)?->number : null, 'cancel_reason' => $trip->cancel_reason,
            'can' => ['reschedule' => $manage && $trip->status === 'scheduled', 'cancel' => $manage && $trip->status === 'scheduled', 'start' => ($manage || $driver) && $trip->status === 'scheduled',
                'finish' => ($manage || $driver) && $trip->status === 'started', 'confirm' => $manage && ! $driver && $trip->status === 'completed'],
        ]]);
    }

    /**
     * Check a trip before saving it: problems that stop it, and warnings that need a reason.
     *
     * @response array{data: array{hard: list<string>, soft: list<string>}}
     */
    public function check(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'departs_at' => ['required', 'date'],
            'returns_at' => ['required', 'date'],
            'passengers' => ['nullable', 'integer', 'min:0', 'max:200'],
            'fleet_unit_id' => ['nullable', 'required_without:fleet_agency_contract_id', 'integer', 'exists:fleet_units,id'],
            'fleet_driver_id' => ['nullable', 'required_without:fleet_agency_contract_id', 'integer', 'exists:fleet_drivers,id'],
            /** A transport company on contract instead of own vehicle and driver. */
            'fleet_agency_contract_id' => ['nullable', 'integer', 'exists:fleet_agency_contracts,id'],
        ]);

        return response()->json(['data' => $this->transport->check($data)]);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function dispatchTrip(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'departs_at' => ['required', 'date'],
            'returns_at' => ['required', 'date'],
            'passengers' => ['nullable', 'integer', 'min:0', 'max:200'],
            'fleet_unit_id' => ['nullable', 'required_without:fleet_agency_contract_id', 'integer', 'exists:fleet_units,id'],
            'fleet_driver_id' => ['nullable', 'required_without:fleet_agency_contract_id', 'integer', 'exists:fleet_drivers,id'],
            /** A transport company on contract instead of own vehicle and driver. */
            'fleet_agency_contract_id' => ['nullable', 'integer', 'exists:fleet_agency_contracts,id'],
            'transport_request_id' => ['nullable', 'integer', 'exists:fleet_transport_requests,id'],
            'purpose' => ['required', 'string', 'max:255'],
            'from_location' => ['nullable', 'string', 'max:150'],
            'destination' => ['required', 'string', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'cargo_weight' => ['nullable', 'numeric', 'min:0'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            /** Needed to go ahead despite warnings (expired insurance, driver on leave, too few seats). */
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $t = $this->transport->dispatch($data, $request->user());

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number]], 201);
    }

    /** @response array{message: string} */
    public function reschedule(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['departs_at' => ['required', 'date'], 'returns_at' => ['required', 'date'], 'override_reason' => ['nullable', 'string', 'max:255']]);
        $this->transport->reschedule($trip, $data['departs_at'], $data['returns_at'], $data['override_reason'] ?? null);

        return response()->json(['message' => 'Trip moved.']);
    }

    /** @response array{message: string} */
    public function cancelTrip(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->transport->cancelTrip($trip, $data['reason']);

        return response()->json(['message' => 'Trip cancelled.']);
    }

    /** @response array{message: string} */
    public function start(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($this->access->manages($request->user()) || $this->isDriver($request->user(), $trip), 403);
        $data = $request->validate([
            /** Odometer at departure (own vehicles). */
            'reading' => ['nullable', 'numeric', 'min:0'],
            'started_at' => ['required', 'date'],
        ]);
        $this->transport->start($trip->load('unit'), isset($data['reading']) ? (float) $data['reading'] : null, $data['started_at']);

        return response()->json(['message' => 'Trip started.']);
    }

    /** @response array{message: string} */
    public function finish(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($this->access->manages($request->user()) || $this->isDriver($request->user(), $trip), 403);
        $data = $request->validate([
            /** Odometer at return (own vehicles). */
            'reading' => ['nullable', 'numeric', 'min:0'],
            'ended_at' => ['required', 'date'],
            /** Transport company trips: km or days, as the contract is paid. */
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->transport->finish($trip->load(['unit', 'contract']), $data, $request->user());

        return response()->json(['message' => 'Logbook saved.']);
    }

    /** @response array{message: string} */
    public function confirm(Request $request, Trip $trip): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $this->transport->confirm($trip->load('driver.employee'), $request->user());

        return response()->json(['message' => 'Logbook confirmed.']);
    }

    /**
     * My trips as driver: coming, on the road and recently done.
     *
     * @response array{data: list<array{id: int, number: string, purpose: string, destination: string, departs_at: string, returns_at: string, vehicle: string|null, driver: string|null, company: string|null, request: string|null, status: string, status_label: string, distance: float|null}>}
     */
    public function myTrips(Request $request): JsonResponse
    {
        $driver = $request->user()->employee ? Driver::where('employee_id', $request->user()->employee->id)->first() : null;
        $trips = $driver ? Trip::with(['unit', 'driver.employee', 'request'])->where('fleet_driver_id', $driver->id)
            ->where(fn ($q) => $q->whereIn('status', Trip::BUSY)->orWhere('departs_at', '>=', now()->subDays(30)))->orderBy('departs_at')->get() : collect();

        return response()->json(['data' => $trips->map(fn ($t) => $this->tripRow($t))->values()]);
    }

    private function tripRow(Trip $t): array
    {
        return ['id' => $t->id, 'number' => $t->number, 'purpose' => $t->purpose, 'destination' => $t->destination, 'departs_at' => $t->departs_at->toIso8601String(),
            'returns_at' => $t->returns_at->toIso8601String(), 'vehicle' => $t->unit?->label(), 'driver' => $t->driver?->name(), 'company' => $t->contract?->label(),
            'request' => $t->request?->number, 'status' => $t->status, 'status_label' => Trip::STATUS_LABELS[$t->status] ?? $t->status, 'distance' => $t->distance()];
    }

    private function isDriver(User $user, Trip $trip): bool
    {
        return $trip->fleet_driver_id && $user->employee && Driver::where('id', $trip->fleet_driver_id)->where('employee_id', $user->employee->id)->exists();
    }

    private function authorizeRequest(User $user, TransportRequest $r): void
    {
        $instance = $r->workflow;
        $approver = $instance && ($instance->steps()->where('acted_by', $user->id)->exists() || app(WorkflowEngine::class)->canAct($user, $instance));
        abort_unless($r->requested_by === $user->id || $this->access->sees($user) || $approver, 403);
    }
}
