<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageDrivers;
use Modules\Fleet\Actions\ManageUnits;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Cost;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Reading;
use Modules\Fleet\Support\FleetAccess;
use Modules\Fleet\Support\FleetFiles;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Fleet: vehicles and generators
 */
class UnitController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageUnits $units) {}

    /**
     * Vehicles and generators.
     *
     * @response array{data: list<array{id: int, kind: string, registration: string|null, name: string, type: string|null, make: string|null, model: string|null, ownership: string, ownership_label: string, department: string|null, status: string, status_label: string, reading: float, unit: string, driver: string|null, alerts: int}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Registration, name, make or model.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('kind', description: '`vehicle` or `generator`.', type: 'string')]
    #[QueryParameter('vehicle_type_id', type: 'integer')]
    #[QueryParameter('sort', description: '`registration`, `name`, `reading`; prefix `-` for descending.', type: 'string', default: 'registration')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', 'registration');
        $sort = in_array(ltrim($sortParam, '-'), ['registration', 'name', 'reading'], true) ? ltrim($sortParam, '-') : 'registration';
        $soon = today()->addDays(30)->toDateString();
        $page = FleetUnit::with(['type', 'department', 'activeAssignment.driver.employee'])
            ->withCount(['compliance as alerts' => fn ($q) => $q->where('is_current', true)->whereDate('expires_on', '<=', $soon)])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('registration', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")
                ->orWhere('make', 'like', "%{$s}%")->orWhere('model', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'disposed'))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('vehicle_type_id'), fn ($q, $t) => $q->where('vehicle_type_id', $t))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (FleetUnit $u) => ['id' => $u->id, 'kind' => $u->kind, 'registration' => $u->registration, 'name' => $u->name, 'type' => $u->type?->name,
                'make' => $u->make, 'model' => $u->model, 'ownership' => $u->ownership, 'ownership_label' => FleetUnit::OWNERSHIP[$u->ownership] ?? $u->ownership, 'department' => $u->department?->name,
                'status' => $u->status, 'status_label' => FleetUnit::STATUSES[$u->status] ?? $u->status, 'reading' => $u->reading, 'unit' => $u->unit(),
                'driver' => $u->activeAssignment?->driver?->name(), 'alerts' => (int) $u->alerts])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * One unit with its readings, drivers, compliance, costs and status history.
     *
     * @response array{data: array{id: int, kind: string, kind_label: string, vehicle_type_id: int|null, type: string|null, licence_classes: list<string>, registration: string|null, name: string, make: string|null, model: string|null, year: int|null, chassis_number: string|null, engine_number: string|null, colour: string|null, seats: int|null, fuel_type: string|null, tank_capacity: float|null, ownership: string, ownership_label: string, owner_name: string|null, asset: array{id: int, number: string, name: string, status: string}|null, company_branch_id: int|null, branch: string|null, department_id: int|null, department: string|null, location: string|null, status: string, status_label: string, status_note: string|null, reading: float, reading_date: string|null, unit: string, notes: string|null, driver: array{assignment_id: int, driver_id: int, name: string, starts_on: string, ends_on: string|null, on_leave: bool}|null, readings: list<array{id: int, reading: float, previous: float|null, date: string, source: string, source_label: string, status: string, note: string|null, by: string|null, can_withdraw: bool}>, assignments: list<array{id: int, driver: string, starts_on: string, ends_on: string|null, ended_on: string|null, note: string|null}>, compliance: list<array{id: int, type: string, type_label: string, reference: string|null, provider: string|null, cover: string|null, starts_on: string|null, expires_on: string, days_left: int, amount: float|null, is_current: bool, has_document: bool}>, costs: list<array{id: int, date: string, category: string, category_label: string, amount: float, description: string|null, from_document: bool, by: string|null}>, cost_total: float, cost_per_unit: float|null, history: list<array{from: string|null, to: string, note: string|null, by: string|null, at: string}>, services: list<array{unit_id: int, unit: string, plan: string, next_reading: float|null, next_date: string|null, left_reading: float|null, days_left: int|null, reading_unit: string, state: string, job: array{id: int, number: string, status_label: string}|null}>, can: array{manage: bool}}}
     */
    public function show(Request $request, FleetUnit $unit, WorkflowEngine $engine): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $unit->load(['type', 'asset', 'branch', 'department', 'readings.creator', 'assignments.driver.employee', 'activeAssignment.driver.employee', 'compliance', 'costs.creator', 'statusChanges.user']);
        $user = $request->user();
        $a = $unit->activeAssignment;
        $first = $unit->readings->where('status', 'approved')->sortBy('date')->first();
        $distance = $first ? $unit->reading - $first->reading : 0;
        $costTotal = round($unit->costs->sum('amount'), 2);

        return response()->json(['data' => [
            'id' => $unit->id, 'kind' => $unit->kind, 'kind_label' => FleetUnit::KINDS[$unit->kind] ?? $unit->kind, 'vehicle_type_id' => $unit->vehicle_type_id, 'type' => $unit->type?->name,
            'licence_classes' => $unit->type?->licence_classes ?? [], 'registration' => $unit->registration, 'name' => $unit->name, 'make' => $unit->make, 'model' => $unit->model, 'year' => $unit->year,
            'chassis_number' => $unit->chassis_number, 'engine_number' => $unit->engine_number, 'colour' => $unit->colour, 'seats' => $unit->seats, 'fuel_type' => $unit->fuel_type,
            'tank_capacity' => $unit->tank_capacity, 'ownership' => $unit->ownership, 'ownership_label' => FleetUnit::OWNERSHIP[$unit->ownership] ?? $unit->ownership, 'owner_name' => $unit->owner_name,
            'asset' => $unit->asset ? ['id' => $unit->asset->id, 'number' => $unit->asset->number, 'name' => $unit->asset->name, 'status' => $unit->asset->status] : null,
            'company_branch_id' => $unit->company_branch_id, 'branch' => $unit->branch?->name, 'department_id' => $unit->department_id, 'department' => $unit->department?->name,
            'location' => $unit->location, 'status' => $unit->status, 'status_label' => FleetUnit::STATUSES[$unit->status] ?? $unit->status, 'status_note' => $unit->status_note,
            'reading' => $unit->reading, 'reading_date' => $unit->reading_date?->toDateString(), 'unit' => $unit->unit(), 'notes' => $unit->notes,
            'driver' => $a ? ['assignment_id' => $a->id, 'driver_id' => $a->fleet_driver_id, 'name' => $a->driver->name(), 'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(), 'on_leave' => $this->onLeave($a->driver)] : null,
            'readings' => $unit->readings->take(100)->map(fn (Reading $r) => ['id' => $r->id, 'reading' => $r->reading, 'previous' => $r->previous, 'date' => $r->date->toDateString(),
                'source' => $r->source, 'source_label' => Reading::SOURCES[$r->source] ?? $r->source, 'status' => $r->status, 'note' => $r->note, 'by' => $r->creator?->display_name,
                'can_withdraw' => $r->status === 'pending' && $r->created_by === $user->id])->values(),
            'assignments' => $unit->assignments->map(fn (Assignment $x) => ['id' => $x->id, 'driver' => $x->driver->name(), 'starts_on' => $x->starts_on->toDateString(),
                'ends_on' => $x->ends_on?->toDateString(), 'ended_on' => $x->ended_on?->toDateString(), 'note' => $x->note])->values(),
            'compliance' => $unit->compliance->map(fn (Compliance $c) => ['id' => $c->id, 'type' => $c->type, 'type_label' => Compliance::TYPES[$c->type] ?? $c->type, 'reference' => $c->reference,
                'provider' => $c->provider ?? $c->supplier?->name, 'cover' => $c->cover, 'starts_on' => $c->starts_on?->toDateString(), 'expires_on' => $c->expires_on->toDateString(),
                'days_left' => (int) today()->diffInDays($c->expires_on, false), 'amount' => $c->amount, 'is_current' => (bool) $c->is_current, 'has_document' => (bool) $c->document_path])->values(),
            'costs' => $unit->costs->take(200)->map(fn (Cost $c) => ['id' => $c->id, 'date' => $c->date->toDateString(), 'category' => $c->category, 'category_label' => Cost::CATEGORIES[$c->category] ?? $c->category,
                'amount' => $c->amount, 'description' => $c->description, 'from_document' => (bool) $c->document_type, 'by' => $c->creator?->display_name])->values(),
            'cost_total' => $costTotal, 'cost_per_unit' => $distance > 0 ? round($costTotal / $distance, 2) : null,
            'history' => $unit->statusChanges->map(fn ($h) => ['from' => $h->from, 'to' => $h->to, 'note' => $h->note, 'by' => $h->user?->display_name, 'at' => $h->created_at->toIso8601String()])->values(),
            'services' => app(\Modules\Fleet\Actions\ManageMaintenance::class)->due($unit)->map(fn ($d) => app(\Modules\Fleet\Actions\ManageMaintenance::class)->dueRow($unit, $d))->values(),
            'can' => ['manage' => $this->access->manages($user)],
        ]]);
    }

    /** @response array{data: array{id: int}} */
    public function store(\Modules\Fleet\Http\Requests\UnitRequest $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validated();
        $u = $this->units->save(null, $data, $request->user());

        return response()->json(['data' => ['id' => $u->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function update(\Modules\Fleet\Http\Requests\UnitRequest $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = collect($request->validated())->except(['kind', 'reading', 'reading_date'])->all();
        $u = $this->units->save($unit, $data, $request->user());

        return response()->json(['data' => ['id' => $u->id]]);
    }

    /** @response array{message: string} */
    public function status(Request $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            /** operational, non_operational, off_limit, lent or disposed. */
            'status' => ['required', 'in:'.implode(',', array_keys(FleetUnit::STATUSES))],
            'note' => ['nullable', 'required_unless:status,operational', 'string', 'max:255'],
        ]);
        $this->units->setStatus($unit, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json(['message' => 'Status changed.']);
    }

    /** @response array{message: string} */
    public function reading(Request $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'reading' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->units->addReading($unit, (float) $data['reading'], $data['date'], 'manual', $data['note'] ?? null, $request->user());

        return response()->json(['message' => 'Reading saved.']);
    }

    /** @response array{message: string} */
    public function correction(Request $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'reading' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $r = $this->units->requestCorrection($unit, (float) $data['reading'], $data['reason'], $request->user());

        return response()->json(['message' => $r->status === 'approved' ? 'Reading corrected.' : 'Correction sent for approval.']);
    }

    /** @response array{message: string} */
    public function withdrawCorrection(Request $request, Reading $reading): JsonResponse
    {
        $this->units->withdrawCorrection($reading, $request->user());

        return response()->json(['message' => 'Correction withdrawn.']);
    }

    /** @response array{message: string} */
    public function assign(Request $request, FleetUnit $unit, ManageDrivers $drivers): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'fleet_driver_id' => ['required', 'integer', 'exists:fleet_drivers,id'],
            'starts_on' => ['required', 'date'],
            /** Leave empty for an open-ended assignment; it ends by itself on this date. */
            'ends_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $drivers->assign($unit, Driver::findOrFail($data['fleet_driver_id']), $data, $request->user());

        return response()->json(['message' => 'Driver assigned.']);
    }

    /** @response array{message: string} */
    public function endAssignment(Request $request, Assignment $assignment, ManageDrivers $drivers): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['date' => ['required', 'date']]);
        $drivers->endAssignment($assignment, $data['date']);

        return response()->json(['message' => 'Assignment ended.']);
    }

    /** @response array{message: string} */
    public function compliance(Request $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            /** insurance, road_licence, inspection, permit or other. */
            'type' => ['required', 'in:'.implode(',', array_keys(Compliance::TYPES))],
            'reference' => ['nullable', 'string', 'max:60'],
            /** Insurer, inspector or office from the supplier register. */
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'provider' => ['nullable', 'string', 'max:150'],
            /** Insurance cover, e.g. comprehensive or third party. */
            'cover' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
            'document' => FleetFiles::RULES,
        ]);
        $this->units->addCompliance($unit, collect($data)->except('document')->all(), $request->file('document'), $request->user());

        return response()->json(['message' => 'Saved.']);
    }

    public function complianceFile(Request $request, Compliance $compliance): StreamedResponse
    {
        abort_unless($this->access->sees($request->user()) && $compliance->document_path, 404);

        return FleetFiles::response($compliance->document_path, (string) $compliance->document_name);
    }

    /** @response array{message: string} */
    public function cost(Request $request, FleetUnit $unit): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            /** fuel, maintenance, insurance, licence, hire, tyres or other. */
            'category' => ['required', 'in:'.implode(',', array_keys(Cost::CATEGORIES))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $this->units->addCost($unit, $data, $request->user());

        return response()->json(['message' => 'Cost added.']);
    }

    public function destroyCost(Request $request, Cost $cost): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $this->units->removeCost($cost);

        return response()->json(['message' => 'Cost removed.']);
    }

    private function onLeave(Driver $driver): bool
    {
        return EmpLeave::where('employee_id', $driver->employee_id)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
            ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists();
    }
}
