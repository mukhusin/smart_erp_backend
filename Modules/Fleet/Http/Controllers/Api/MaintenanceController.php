<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageMaintenance;
use Modules\Fleet\Models\Accident;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Maintenance;
use Modules\Fleet\Models\MaintenanceLine;
use Modules\Fleet\Models\ServicePlan;
use Modules\Fleet\Support\FleetAccess;
use Modules\Fleet\Support\FleetFiles;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Fleet: maintenance
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageMaintenance $maintenance) {}

    /**
     * Service plans (every km / hours or months).
     *
     * @response array{data: list<array{id: int, name: string, vehicle_type_id: int|null, vehicle_type: string|null, fleet_unit_id: int|null, unit: string|null, every_reading: float|null, every_months: int|null, warn_reading: float, warn_days: int, tasks: string|null, is_active: bool}>}
     */
    public function plans(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);

        return response()->json(['data' => ServicePlan::with(['type', 'unit'])->orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'vehicle_type_id' => $p->vehicle_type_id,
            'vehicle_type' => $p->type?->name, 'fleet_unit_id' => $p->fleet_unit_id, 'unit' => $p->unit?->label(), 'every_reading' => $p->every_reading, 'every_months' => $p->every_months,
            'warn_reading' => $p->warn_reading, 'warn_days' => $p->warn_days, 'tasks' => $p->tasks, 'is_active' => (bool) $p->is_active])->values()]);
    }

    /** @response array{data: array{id: int}} */
    public function storePlan(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $p = ServicePlan::create($this->planData($request->validate([
            'name' => ['required', 'string', 'max:100'],
            /** For all units of a type, or for one unit. */
            'vehicle_type_id' => ['nullable', 'required_without:fleet_unit_id', 'integer', 'exists:fleet_vehicle_types,id'],
            'fleet_unit_id' => ['nullable', 'integer', 'exists:fleet_units,id'],
            /** Every so many km (vehicles) or running hours (generators). */
            'every_reading' => ['nullable', 'required_without:every_months', 'numeric', 'min:1'],
            'every_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'warn_reading' => ['nullable', 'numeric', 'min:0'],
            'warn_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tasks' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['nullable', 'boolean'],
        ])));

        return response()->json(['data' => ['id' => $p->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updatePlan(Request $request, ServicePlan $plan): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $plan->update($this->planData($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'vehicle_type_id' => ['nullable', 'required_without:fleet_unit_id', 'integer', 'exists:fleet_vehicle_types,id'],
            'fleet_unit_id' => ['nullable', 'integer', 'exists:fleet_units,id'],
            'every_reading' => ['nullable', 'required_without:every_months', 'numeric', 'min:1'],
            'every_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'warn_reading' => ['nullable', 'numeric', 'min:0'],
            'warn_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tasks' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['nullable', 'boolean'],
        ])));

        return response()->json(['data' => ['id' => $plan->id]]);
    }

    /**
     * Services due soon or overdue across the fleet.
     *
     * @response array{data: list<array{unit_id: int, unit: string, plan: string, next_reading: float|null, next_date: string|null, left_reading: float|null, days_left: int|null, reading_unit: string, state: string, job: array{id: int, number: string, status_label: string}|null}>}
     */
    public function due(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $rows = [];
        foreach (FleetUnit::whereNotIn('status', ['disposed'])->orderBy('registration')->get() as $u) {
            foreach ($this->maintenance->due($u)->where('state', '!=', 'ok') as $d) {
                $rows[] = $this->maintenance->dueRow($u, $d);
            }
        }

        return response()->json(['data' => collect($rows)->sortBy(fn ($r) => $r['state'] === 'overdue' ? 0 : 1)->values()]);
    }

    /**
     * Repair and service jobs.
     *
     * @response array{data: list<array{id: int, number: string, unit: string|null, kind: string, kind_label: string, description: string, estimate: float, actual_cost: float|null, status: string, status_label: string, waiting_for: string|null, created_at: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('status', description: 'A status, or `open`.', type: 'string')]
    #[QueryParameter('kind', type: 'string')]
    #[QueryParameter('unit_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = ! $this->access->sees($user);
        $status = (string) $request->query('status');
        $page = Maintenance::with(['unit', 'workflow.steps'])
            ->when($mine, fn ($q) => $q->where('requested_by', $user->id))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Maintenance::OPEN), fn ($q) => $q->when($status, fn ($q) => $q->where('status', $status)))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('unit_id'), fn ($q, $u) => $q->where('fleet_unit_id', $u))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")
                ->orWhereHas('unit', fn ($u) => $u->where('registration', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%"))))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Maintenance $m) => ['id' => $m->id, 'number' => $m->number, 'unit' => $m->unit?->label(), 'kind' => $m->kind,
                'kind_label' => Maintenance::KINDS[$m->kind] ?? $m->kind, 'description' => $m->description, 'estimate' => $m->estimate, 'actual_cost' => $m->actual_cost, 'status' => $m->status,
                'status_label' => Maintenance::STATUS_LABELS[$m->status] ?? $m->status,
                'waiting_for' => in_array($m->status, ['pending', 'inspection'], true) ? $m->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'created_at' => $m->created_at->toIso8601String()])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A job with its inspection lines and the requisition that buys the work.
     *
     * @response array{data: array{id: int, number: string, unit_id: int, unit: string|null, reading_unit: string, unit_reading: float|null, kind: string, kind_label: string, plan: string|null, accident: array{id: int, number: string}|null, description: string, reading: float|null, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, supplier_id: int|null, workshop: string|null, findings: string|null, estimate: float, department_id: int|null, budget_item_id: int|null, lines: list<array{id: int, kind: string, kind_label: string, description: string, quantity: float, unit_cost: float, amount: float}>, requisition: array{id: int, number: string, status: string, status_label: string}|null, started_on: string|null, completed_on: string|null, completed_reading: float|null, actual_cost: float|null, invoice_reference: string|null, completion_notes: string|null, requested_by: string|null, inspected_by: string|null, created_at: string, can: array{withdraw: bool, cancel: bool, inspect: bool, start: bool, complete: bool}}}
     */
    public function show(Request $request, Maintenance $maintenance, WorkflowEngine $engine): JsonResponse
    {
        $m = $maintenance->load(['unit', 'plan', 'accident', 'lines', 'supplier', 'requester', 'inspector', 'workflow.steps']);
        $user = $request->user();
        $approver = $m->workflow && ($m->workflow->steps()->where('acted_by', $user->id)->exists() || $engine->canAct($user, $m->workflow));
        abort_unless($m->requested_by === $user->id || $this->access->sees($user) || $approver, 403);
        $manage = $this->access->manages($user);
        $req = $m->purchase_requisition_id ? PurchaseRequisition::find($m->purchase_requisition_id) : null;

        return response()->json(['data' => [
            'id' => $m->id, 'number' => $m->number, 'unit_id' => $m->fleet_unit_id, 'unit' => $m->unit?->label(), 'reading_unit' => (string) $m->unit?->unit(), 'unit_reading' => $m->unit?->reading,
            'kind' => $m->kind, 'kind_label' => Maintenance::KINDS[$m->kind] ?? $m->kind, 'plan' => $m->plan?->name, 'accident' => $m->accident ? ['id' => $m->accident->id, 'number' => $m->accident->number] : null,
            'description' => $m->description, 'reading' => $m->reading, 'status' => $m->status, 'status_label' => Maintenance::STATUS_LABELS[$m->status] ?? $m->status,
            'waiting_for' => in_array($m->status, ['pending', 'inspection'], true) ? $m->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $m->decision_comment,
            'supplier_id' => $m->supplier_id, 'workshop' => $m->supplier?->name ?? $m->workshop, 'findings' => $m->findings, 'estimate' => $m->estimate, 'department_id' => $m->department_id,
            'budget_item_id' => $m->budget_item_id,
            'lines' => $m->lines->map(fn (MaintenanceLine $l) => ['id' => $l->id, 'kind' => $l->kind, 'kind_label' => MaintenanceLine::KINDS[$l->kind] ?? $l->kind, 'description' => $l->description,
                'quantity' => $l->quantity, 'unit_cost' => $l->unit_cost, 'amount' => $l->amount])->values(),
            'requisition' => $req ? ['id' => $req->id, 'number' => $req->number, 'status' => $req->status, 'status_label' => PurchaseRequisition::STATUS_LABELS[$req->status] ?? $req->status] : null,
            'started_on' => $m->started_on?->toDateString(), 'completed_on' => $m->completed_on?->toDateString(), 'completed_reading' => $m->completed_reading, 'actual_cost' => $m->actual_cost,
            'invoice_reference' => $m->invoice_reference, 'completion_notes' => $m->completion_notes, 'requested_by' => $m->requester?->display_name, 'inspected_by' => $m->inspector?->display_name,
            'created_at' => $m->created_at->toIso8601String(),
            'can' => ['withdraw' => $m->status === 'pending' && $m->requested_by === $user->id, 'cancel' => $manage && in_array($m->status, ['approved', 'sourcing'], true),
                'inspect' => $manage && $m->status === 'approved', 'start' => $manage && $m->status === 'sourcing', 'complete' => $manage && $m->status === 'in_repair'],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fleet_unit_id' => ['required', 'integer', 'exists:fleet_units,id'],
            /** corrective (repair) or preventive (service). */
            'kind' => ['required', 'in:corrective,preventive'],
            'fleet_service_plan_id' => ['nullable', 'integer', 'exists:fleet_service_plans,id'],
            'description' => ['required', 'string', 'max:3000'],
            'reading' => ['nullable', 'numeric', 'min:0'],
        ]);
        abort_unless($this->access->manages($request->user()) || in_array((int) $data['fleet_unit_id'], $this->myUnits($request->user()), true), 403, 'You can report faults only on the vehicles you drive.');
        $m = $this->maintenance->request($data, $request->user());

        return response()->json(['data' => ['id' => $m->id, 'number' => $m->number, 'status' => $m->status]], 201);
    }

    /** @response array{message: string} */
    public function withdraw(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->maintenance->withdraw($maintenance, $request->user());

        return response()->json(['message' => 'Request withdrawn.']);
    }

    /** @response array{message: string} */
    public function cancel(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->maintenance->cancel($maintenance, $data['reason']);

        return response()->json(['message' => 'Job cancelled. Cancel its requisition in Procurement if one was raised.']);
    }

    /** @response array{message: string} */
    public function inspect(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'findings' => ['required', 'string', 'max:3000'],
            /** Workshop from the supplier register, or a name for an in-house or other workshop. */
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'workshop' => ['nullable', 'string', 'max:150'],
            'lines' => ['present', 'array', 'max:100'],
            /** parts, labour or other. */
            'lines.*.kind' => ['nullable', 'in:parts,labour,other'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            /** Needed when there is a cost: the requisition is charged here. */
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'budget_item_id' => ['nullable', 'integer', 'exists:budget_items,id'],
        ]);
        $m = $this->maintenance->inspect($maintenance, $data, $request->user());

        return response()->json(['message' => $m->status === 'sourcing' ? 'Inspection saved.' : 'Inspection sent for approval.']);
    }

    /** @response array{message: string} */
    public function start(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate(['date' => ['required', 'date', 'before_or_equal:today']]);
        $this->maintenance->start($maintenance->load('unit'), $data['date'], $request->user());

        return response()->json(['message' => 'Unit is in the workshop.']);
    }

    /** @response array{message: string} */
    public function complete(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'completed_on' => ['required', 'date', 'before_or_equal:today'],
            'reading' => ['nullable', 'numeric', 'min:0'],
            /** Final cost; defaults to the inspection estimate. */
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'invoice_reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $this->maintenance->complete($maintenance->load(['unit', 'accident']), $data, $request->user());

        return response()->json(['message' => 'Job completed; the unit is back in service.']);
    }

    /**
     * Accidents.
     *
     * @response array{data: list<array{id: int, number: string, unit: string|null, driver: string|null, happened_at: string, location: string, injuries: bool, status: string, status_label: string, outcome: string|null}>}
     */
    public function accidents(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = Accident::with(['unit', 'driver.employee'])->when(! $this->access->sees($user), fn ($q) => $q->where('reported_by', $user->id))->orderByDesc('happened_at')->limit(500)->get();

        return response()->json(['data' => $list->map(fn (Accident $a) => ['id' => $a->id, 'number' => $a->number, 'unit' => $a->unit?->label(), 'driver' => $a->driver?->name(),
            'happened_at' => $a->happened_at->toIso8601String(), 'location' => $a->location, 'injuries' => (bool) $a->injuries, 'status' => $a->status,
            'status_label' => Accident::STATUS_LABELS[$a->status] ?? $a->status, 'outcome' => $a->outcome ? (Accident::OUTCOMES[$a->outcome] ?? $a->outcome) : null])->values()]);
    }

    /**
     * An accident.
     *
     * @response array{data: array{id: int, number: string, unit_id: int, unit: string|null, has_asset: bool, driver: string|null, happened_at: string, location: string, description: string, injuries: bool, third_party: string|null, police_report: string|null, photos: list<array{index: int, name: string}>, status: string, status_label: string, waiting_for: string|null, outcome: string|null, outcome_label: string|null, assessment: string|null, insurance_claim: string|null, decision_comment: string|null, reported_by: string|null, maintenance: array{id: int, number: string, status_label: string}|null, can: array{assess: bool}}}
     */
    public function accident(Request $request, Accident $accident, WorkflowEngine $engine): JsonResponse
    {
        $a = $accident->load(['unit', 'driver.employee', 'reporter', 'maintenance', 'workflow.steps']);
        $user = $request->user();
        $approver = $a->workflow && ($a->workflow->steps()->where('acted_by', $user->id)->exists() || $engine->canAct($user, $a->workflow));
        abort_unless($a->reported_by === $user->id || $this->access->sees($user) || $approver, 403);

        return response()->json(['data' => [
            'id' => $a->id, 'number' => $a->number, 'unit_id' => $a->fleet_unit_id, 'unit' => $a->unit?->label(), 'has_asset' => (bool) $a->unit?->asset_id, 'driver' => $a->driver?->name(),
            'happened_at' => $a->happened_at->toIso8601String(), 'location' => $a->location, 'description' => $a->description, 'injuries' => (bool) $a->injuries, 'third_party' => $a->third_party,
            'police_report' => $a->police_report, 'photos' => collect($a->photos ?? [])->values()->map(fn ($p, $i) => ['index' => $i, 'name' => (string) ($p['name'] ?? 'photo')])->values(),
            'status' => $a->status, 'status_label' => Accident::STATUS_LABELS[$a->status] ?? $a->status,
            'waiting_for' => $a->status === 'pending' ? $a->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'outcome' => $a->outcome, 'outcome_label' => $a->outcome ? (Accident::OUTCOMES[$a->outcome] ?? $a->outcome) : null, 'assessment' => $a->assessment, 'insurance_claim' => $a->insurance_claim,
            'decision_comment' => $a->decision_comment, 'reported_by' => $a->reporter?->display_name,
            'maintenance' => $a->maintenance ? ['id' => $a->maintenance->id, 'number' => $a->maintenance->number, 'status_label' => Maintenance::STATUS_LABELS[$a->maintenance->status] ?? $a->maintenance->status] : null,
            'can' => ['assess' => $this->access->manages($user) && $a->status === 'reviewed' && ! $a->outcome],
        ]]);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fleet_unit_id' => ['required', 'integer', 'exists:fleet_units,id'],
            'fleet_trip_id' => ['nullable', 'integer', 'exists:fleet_trips,id'],
            'happened_at' => ['required', 'date'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'injuries' => ['nullable', 'boolean'],
            /** Other vehicles, people or property involved. */
            'third_party' => ['nullable', 'string', 'max:3000'],
            /** Police report (RB) number. */
            'police_report' => ['nullable', 'string', 'max:60'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        abort_unless($this->access->manages($request->user()) || in_array((int) $data['fleet_unit_id'], $this->myUnits($request->user()), true), 403, 'You can report accidents only on the vehicles you drive.');
        $a = $this->maintenance->report(collect($data)->except('photos')->all(), $request->file('photos') ?? [], $request->user());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number]], 201);
    }

    public function photo(Request $request, Accident $accident, int $index): StreamedResponse
    {
        abort_unless($accident->reported_by === $request->user()->id || $this->access->sees($request->user()), 403);
        $p = ($accident->photos ?? [])[$index] ?? null;
        abort_unless($p, 404);

        return FleetFiles::response($p['path'], (string) ($p['name'] ?? 'photo'));
    }

    /** @response array{message: string} */
    public function assess(Request $request, Accident $accident): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            /** repair (opens a repair job), write_off (not repairable) or none. */
            'outcome' => ['required', 'in:repair,write_off,none'],
            'assessment' => ['required', 'string', 'max:3000'],
            'insurance_claim' => ['nullable', 'string', 'max:60'],
        ]);
        $this->maintenance->assess($accident->load('unit'), $data, $request->user());

        return response()->json(['message' => 'Assessment saved.']);
    }

    private function planData(array $d): array
    {
        return ['name' => trim($d['name']), 'vehicle_type_id' => ! empty($d['fleet_unit_id']) ? null : ($d['vehicle_type_id'] ?? null), 'fleet_unit_id' => $d['fleet_unit_id'] ?? null,
            'every_reading' => $d['every_reading'] ?? null, 'every_months' => $d['every_months'] ?? null, 'warn_reading' => $d['warn_reading'] ?? 500, 'warn_days' => $d['warn_days'] ?? 14,
            'tasks' => $d['tasks'] ?? null, 'is_active' => (bool) ($d['is_active'] ?? true)];
    }

    /** @return list<int> */
    private function myUnits(User $user): array
    {
        $driver = $user->employee ? Driver::where('employee_id', $user->employee->id)->value('id') : null;

        return $driver ? Assignment::where('fleet_driver_id', $driver)->whereNull('ended_on')->pluck('fleet_unit_id')->map(fn ($v) => (int) $v)->all() : [];
    }
}
