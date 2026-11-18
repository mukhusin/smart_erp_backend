<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Models\Asset;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Cost;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\VehicleType;
use Modules\Fleet\Support\FleetAccess;
use Modules\Procurement\Models\Supplier;

/**
 * @tags Fleet: setup
 */
class FleetSetupController extends Controller
{
    public function __construct(private readonly FleetAccess $access) {}

    /**
     * Start page figures.
     *
     * @response array{data: array{units: int, vehicles: int, generators: int, by_status: list<array{status: string, label: string, count: int}>, drivers: int, unassigned: int, due_soon: int, overdue: int, cost_this_year: float, cost_by_category: list<array{category: string, amount: float}>, due: list<array{kind: string, what: string, for: string, link: string, expires_on: string, days_left: int}>}}
     */
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $units = FleetUnit::where('status', '!=', 'disposed')->withCount(['assignments as active' => fn ($q) => $q->whereNull('ended_on')])->get();
        $due = $this->calendar(60);
        $costs = Cost::whereYear('date', today()->year)->whereHas('unit', fn ($q) => $q)->get();

        return response()->json(['data' => [
            'units' => $units->count(), 'vehicles' => $units->where('kind', 'vehicle')->count(), 'generators' => $units->where('kind', 'generator')->count(),
            'by_status' => collect(FleetUnit::STATUSES)->except('disposed')->map(fn ($l, $s) => ['status' => $s, 'label' => $l, 'count' => $units->where('status', $s)->count()])->values(),
            'drivers' => Driver::where('status', 'active')->count(), 'unassigned' => $units->where('kind', 'vehicle')->where('active', 0)->count(),
            'due_soon' => $due->where('days_left', '>=', 0)->where('days_left', '<=', 30)->count(), 'overdue' => $due->where('days_left', '<', 0)->count(),
            'cost_this_year' => round($costs->sum('amount'), 2),
            'cost_by_category' => $costs->groupBy('category')->map(fn ($g, $c) => ['category' => Cost::CATEGORIES[$c] ?? $c, 'amount' => round($g->sum('amount'), 2)])->values(),
            'due' => $due->take(10)->values(),
        ]]);
    }

    /**
     * Insurance, licences, certificates and driving licences that expire within the days given (and those already expired).
     *
     * @response array{data: list<array{kind: string, what: string, for: string, link: string, expires_on: string, days_left: int}>}
     */
    #[QueryParameter('days', type: 'integer', default: 90)]
    public function compliance(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);

        return response()->json(['data' => $this->calendar(min(max((int) $request->query('days', 90), 1), 730))->values()]);
    }

    /**
     * Choice lists for the fleet forms.
     *
     * @response array{data: array{types: list<array{id: int, name: string, kind: string, licence_classes: list<string>, tank_capacity: float|null, is_active: bool}>, branches: list<array{id: int, name: string}>, departments: list<array{id: int, name: string}>, assets: list<array{id: int, label: string, details: array<string, mixed>}>, suppliers: list<array{id: int, name: string}>, statuses: list<array{value: string, label: string}>, ownership: list<array{value: string, label: string}>, fuels: list<array{value: string, label: string}>, compliance_types: list<array{value: string, label: string}>, cost_categories: list<array{value: string, label: string}>}}
     */
    public function lookups(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $linked = FleetUnit::whereNotNull('asset_id')->pluck('asset_id');
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => [
            'types' => VehicleType::orderBy('kind')->orderBy('name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'licence_classes' => $t->licence_classes ?? [],
                'tank_capacity' => $t->tank_capacity, 'is_active' => (bool) $t->is_active])->values(),
            'branches' => CompanyBranch::orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'assets' => $this->access->manages($request->user()) ? Asset::where('asset_class', 'vehicle')->where('status', '!=', 'disposed')->whereNotIn('id', $linked)->orderBy('number')->limit(500)->get()
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->number} {$a->name}", 'details' => (object) ($a->details ?? [])])->values() : [],
            'suppliers' => class_exists(Supplier::class) ? Supplier::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values() : [],
            'statuses' => $opt(FleetUnit::STATUSES), 'ownership' => $opt(FleetUnit::OWNERSHIP), 'fuels' => $opt(FleetUnit::FUELS),
            'compliance_types' => $opt(Compliance::TYPES), 'cost_categories' => $opt(Cost::CATEGORIES),
        ]]);
    }

    /** @response array{data: array{id: int}} */
    public function storeType(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $t = VehicleType::create($this->typeData($request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fleet_vehicle_types,name,NULL,id,company_id,'.app(\App\Services\CompanyContext::class)->id()],
            /** vehicle or generator. */
            'kind' => ['required', 'in:vehicle,generator'],
            /** Driving licence classes that allow driving this type (any one is enough). */
            'licence_classes' => ['nullable', 'array', 'max:20'],
            'licence_classes.*' => ['string', 'max:5'],
            'tank_capacity' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ])));

        return response()->json(['data' => ['id' => $t->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateType(Request $request, VehicleType $type): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $type->update($this->typeData($request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fleet_vehicle_types,name,'.$type->id.',id,company_id,'.$type->company_id],
            'kind' => ['required', 'in:vehicle,generator'],
            'licence_classes' => ['nullable', 'array', 'max:20'],
            'licence_classes.*' => ['string', 'max:5'],
            'tank_capacity' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ])));

        return response()->json(['data' => ['id' => $type->id]]);
    }

    private function typeData(array $d): array
    {
        return ['name' => trim($d['name']), 'kind' => $d['kind'], 'tank_capacity' => $d['tank_capacity'] ?? null, 'is_active' => (bool) ($d['is_active'] ?? true),
            'licence_classes' => collect($d['licence_classes'] ?? [])->map(fn ($c) => strtoupper(trim($c)))->filter()->unique()->values()->all()];
    }

    private function calendar(int $days)
    {
        $until = today()->addDays($days);
        $items = Compliance::with('unit')->where('is_current', true)->whereHas('unit', fn ($q) => $q->where('status', '!=', 'disposed'))->whereDate('expires_on', '<=', $until)->get()
            ->map(fn ($c) => ['kind' => 'unit', 'what' => Compliance::TYPES[$c->type] ?? $c->type, 'for' => $c->unit->label(), 'link' => "/fleet/units/{$c->fleet_unit_id}",
                'expires_on' => $c->expires_on->toDateString(), 'days_left' => (int) today()->diffInDays($c->expires_on, false)]);
        $licences = Driver::with(['employee', 'licences'])->where('status', 'active')->get()->map(fn ($d) => [$d, $d->currentLicence()])
            ->filter(fn ($x) => $x[1] && $x[1]->end_date->lte($until))
            ->map(fn ($x) => ['kind' => 'driver', 'what' => 'Driving licence', 'for' => $x[0]->name(), 'link' => "/fleet/drivers/{$x[0]->id}",
                'expires_on' => $x[1]->end_date->toDateString(), 'days_left' => (int) today()->diffInDays($x[1]->end_date, false)]);

        return $items->concat($licences)->sortBy('days_left')->values();
    }
}
