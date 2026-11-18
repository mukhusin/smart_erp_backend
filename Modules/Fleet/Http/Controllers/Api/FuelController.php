<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\AccessService;
use Modules\Fleet\Actions\ManageFuel;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\FuelBatch;
use Modules\Fleet\Models\FuelMovement;
use Modules\Fleet\Models\FuelRecharge;
use Modules\Fleet\Models\FuelRequest;
use Modules\Fleet\Models\FuelStore;
use Modules\Fleet\Models\Reading;
use Modules\Fleet\Support\FleetAccess;
use Modules\Payments\Models\PaymentRequest;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Fleet: fuel
 */
class FuelController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageFuel $fuel, private readonly AccessService $perms) {}

    /**
     * Fuel requests: all for the fleet office and fuel issuers, own ones otherwise.
     *
     * @response array{data: list<array{id: int, number: string, unit: string|null, store: string|null, litres: float, issued_litres: float|null, amount: float|null, warnings: int, requested_by: string|null, created_at: string, status: string, status_label: string, waiting_for: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('mine', type: 'boolean')]
    #[QueryParameter('unit_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = $request->boolean('mine') || ! ($this->access->sees($user) || $this->issuer($user));
        $page = FuelRequest::with(['unit', 'store', 'requester', 'workflow.steps'])
            ->when($mine, fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('unit_id'), fn ($q, $u) => $q->where('fleet_unit_id', $u))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhereHas('unit', fn ($u) => $u->where('registration', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%"))))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (FuelRequest $r) => ['id' => $r->id, 'number' => $r->number, 'unit' => $r->unit?->label(), 'store' => $r->store?->name, 'litres' => $r->litres,
                'issued_litres' => $r->issued_litres, 'amount' => $r->amount, 'warnings' => count($r->warnings ?? []), 'requested_by' => $r->requester?->display_name,
                'created_at' => $r->created_at->toIso8601String(), 'status' => $r->status, 'status_label' => FuelRequest::STATUS_LABELS[$r->status] ?? $r->status,
                'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * What the fuel request form needs: units the user may ask for, stores, and each unit's usual consumption.
     *
     * @response array{data: array{units: list<array{id: int, label: string, kind: string, fuel_type: string|null, reading: float, unit: string, tank: float|null, per_litre: float|null, last_fill_reading: float|null}>, stores: list<array{id: int, name: string, kind: string, fuel_type: string, free: float|null, price: float|null}>}}
     */
    public function form(Request $request): JsonResponse
    {
        $user = $request->user();
        $units = FleetUnit::with('type')->where('status', 'operational')->when(! $this->access->manages($user), fn ($q) => $q->whereIn('id', $this->myUnits($user)))->orderBy('registration')->get();

        return response()->json(['data' => [
            'units' => $units->map(function ($u) {
                $c = $this->fuel->consumption($u);

                return ['id' => $u->id, 'label' => $u->label(), 'kind' => $u->kind, 'fuel_type' => $u->fuel_type, 'reading' => $u->reading, 'unit' => $u->unit(),
                    'tank' => $u->tank_capacity ?: $u->type?->tank_capacity, 'per_litre' => $c['per_litre'], 'last_fill_reading' => $c['last_reading']];
            })->values(),
            'stores' => FuelStore::where('is_active', true)->orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'fuel_type' => $s->fuel_type,
                'free' => $s->isOwn() ? round($s->balance() - $s->committed(), 2) : null, 'price' => $s->isOwn() ? $s->averagePrice() : $s->price])->values(),
        ]]);
    }

    /**
     * Check a fuel request before sending it.
     *
     * @response array{data: array{hard: list<string>, soft: list<string>}}
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fleet_unit_id' => ['required', 'integer', 'exists:fleet_units,id'],
            'fleet_fuel_store_id' => ['required', 'integer', 'exists:fleet_fuel_stores,id'],
            'litres' => ['required', 'numeric', 'min:0.1'],
            'reading' => ['required', 'numeric', 'min:0'],
        ]);
        $unit = FleetUnit::with('type')->findOrFail($data['fleet_unit_id']);
        abort_unless($this->access->manages($request->user()) || in_array($unit->id, $this->myUnits($request->user()), true), 403);

        return response()->json(['data' => $this->fuel->check($unit, FuelStore::findOrFail($data['fleet_fuel_store_id']), (float) $data['litres'], (float) $data['reading'])]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fleet_unit_id' => ['required', 'integer', 'exists:fleet_units,id'],
            'fleet_fuel_store_id' => ['required', 'integer', 'exists:fleet_fuel_stores,id'],
            'litres' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            /** Odometer (km) or running hours now. */
            'reading' => ['required', 'numeric', 'min:0'],
            'fleet_trip_id' => ['nullable', 'integer', 'exists:fleet_trips,id'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        abort_unless($this->access->manages($request->user()) || in_array((int) $data['fleet_unit_id'], $this->myUnits($request->user()), true), 403, 'You can ask for fuel only for the vehicles you drive.');
        $r = $this->fuel->request($data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /**
     * A fuel request.
     *
     * @response array{data: array{id: int, number: string, unit_id: int, unit: string|null, reading_unit: string, store_id: int, store: string|null, store_kind: string|null, litres: float, reading: float, purpose: string|null, trip: string|null, department: string|null, warnings: list<string>, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, requested_by: string|null, created_at: string, issued_litres: float|null, issue_reading: float|null, unit_price: float|null, amount: float|null, receipt_reference: string|null, issued_at: string|null, issued_by: string|null, store_price: float|null, can: array{withdraw: bool, cancel: bool, issue: bool}}}
     */
    public function show(Request $request, FuelRequest $fuelRequest, WorkflowEngine $engine): JsonResponse
    {
        $r = $fuelRequest->load(['unit', 'store', 'trip', 'department', 'requester', 'issuer', 'workflow.steps']);
        $user = $request->user();
        $approver = $r->workflow && ($r->workflow->steps()->where('acted_by', $user->id)->exists() || $engine->canAct($user, $r->workflow));
        abort_unless($r->requested_by === $user->id || $this->access->sees($user) || $this->issuer($user) || $approver, 403);
        $mine = $r->requested_by === $user->id;

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'unit_id' => $r->fleet_unit_id, 'unit' => $r->unit?->label(), 'reading_unit' => (string) $r->unit?->unit(), 'store_id' => $r->fleet_fuel_store_id,
            'store' => $r->store?->name, 'store_kind' => $r->store?->kind, 'litres' => $r->litres, 'reading' => $r->reading, 'purpose' => $r->purpose, 'trip' => $r->trip?->number,
            'department' => $r->department?->name, 'warnings' => $r->warnings ?? [], 'status' => $r->status, 'status_label' => FuelRequest::STATUS_LABELS[$r->status] ?? $r->status,
            'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $r->decision_comment,
            'requested_by' => $r->requester?->display_name, 'created_at' => $r->created_at->toIso8601String(), 'issued_litres' => $r->issued_litres, 'issue_reading' => $r->issue_reading,
            'unit_price' => $r->unit_price, 'amount' => $r->amount, 'receipt_reference' => $r->receipt_reference, 'issued_at' => $r->issued_at?->toIso8601String(),
            'issued_by' => $r->issuer?->display_name, 'store_price' => $r->store ? ($r->store->isOwn() ? $r->store->averagePrice() : $r->store->price) : null,
            'can' => ['withdraw' => $mine && $r->status === 'pending', 'cancel' => ($mine || $this->access->manages($user)) && $r->status === 'approved',
                'issue' => $this->issuer($user) && ! $mine && $r->status === 'approved'],
        ]]);
    }

    /** @response array{message: string} */
    public function withdraw(Request $request, FuelRequest $fuelRequest): JsonResponse
    {
        $this->fuel->withdraw($fuelRequest, $request->user());

        return response()->json(['message' => 'Request withdrawn.']);
    }

    /** @response array{message: string} */
    public function cancel(Request $request, FuelRequest $fuelRequest): JsonResponse
    {
        abort_unless($fuelRequest->requested_by === $request->user()->id || $this->access->manages($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->fuel->cancel($fuelRequest, $data['reason']);

        return response()->json(['message' => 'Request cancelled.']);
    }

    /** @response array{message: string} */
    public function issue(Request $request, FuelRequest $fuelRequest): JsonResponse
    {
        abort_unless($this->issuer($request->user()), 403);
        $data = $request->validate([
            /** Litres actually given; less than approved when supply is short. */
            'litres' => ['required', 'numeric', 'min:0.1'],
            'reading' => ['required', 'numeric', 'min:0'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:now'],
            /** Station accounts: price per litre and number on the station receipt. */
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'receipt_reference' => ['nullable', 'string', 'max:60'],
        ]);
        $this->fuel->issue($fuelRequest, $data, $request->user());

        return response()->json(['message' => 'Fuel issued.']);
    }

    /**
     * Fuel stores with balances, deliveries and station batches.
     *
     * @response array{data: list<array{id: int, name: string, kind: string, kind_label: string, fuel_type: string, capacity: float|null, reorder_level: float|null, supplier_id: int|null, supplier: string|null, account_reference: string|null, price: float|null, is_active: bool, balance: float|null, committed: float, average_price: float|null, low: bool, unpaid: float|null}>, recharges: list<array{id: int, number: string, store: string|null, supplier: string|null, date: string, litres: float, amount: float, status: string, status_label: string, received_litres: float|null, payment_request: array{id: int, number: string, status_label: string}|null}>, batches: list<array{id: int, number: string, store: string|null, from_date: string, to_date: string, litres: float, total: float, payment_request: array{id: int, number: string, status_label: string}|null}>}
     */
    public function stores(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()) || $this->issuer($request->user()), 403);
        $stores = FuelStore::with('supplier')->orderBy('name')->get();
        $pr = fn ($p) => $p ? ['id' => $p->id, 'number' => $p->number, 'status_label' => PaymentRequest::STATUS_LABELS[$p->status] ?? $p->status] : null;

        return response()->json([
            'data' => $stores->map(function (FuelStore $s) {
                $balance = $s->isOwn() ? $s->balance() : null;

                return ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => FuelStore::KINDS[$s->kind] ?? $s->kind, 'fuel_type' => $s->fuel_type, 'capacity' => $s->capacity,
                    'reorder_level' => $s->reorder_level, 'supplier_id' => $s->supplier_id, 'supplier' => $s->supplier?->name, 'account_reference' => $s->account_reference, 'price' => $s->price,
                    'is_active' => (bool) $s->is_active, 'balance' => $balance, 'committed' => $s->committed(), 'average_price' => $s->isOwn() ? $s->averagePrice() : null,
                    'low' => $balance !== null && $s->reorder_level !== null && $balance <= $s->reorder_level,
                    'unpaid' => $s->isOwn() ? null : round((float) $s->requests()->where('status', 'issued')->whereNull('fleet_fuel_batch_id')->sum('amount'), 2)];
            })->values(),
            'recharges' => FuelRecharge::with(['store', 'supplier', 'paymentRequest'])->orderByDesc('id')->limit(200)->get()->map(fn ($r) => ['id' => $r->id, 'number' => $r->number,
                'store' => $r->store?->name, 'supplier' => $r->supplier?->name, 'date' => $r->date->toDateString(), 'litres' => $r->litres, 'amount' => $r->amount, 'status' => $r->status,
                'status_label' => FuelRecharge::STATUS_LABELS[$r->status] ?? $r->status, 'received_litres' => $r->received_litres, 'payment_request' => $pr($r->paymentRequest)])->values(),
            'batches' => FuelBatch::with(['store', 'paymentRequest'])->orderByDesc('id')->limit(200)->get()->map(fn ($b) => ['id' => $b->id, 'number' => $b->number, 'store' => $b->store?->name,
                'from_date' => $b->from_date->toDateString(), 'to_date' => $b->to_date->toDateString(), 'litres' => $b->litres, 'total' => $b->total, 'payment_request' => $pr($b->paymentRequest)])->values(),
        ]);
    }

    /**
     * Stock movements of an own tank.
     *
     * @response array{data: list<array{id: int, date: string, type: string, type_label: string, litres: float, unit_price: float, amount: float, note: string|null, by: string|null}>, balance: float}
     */
    public function movements(Request $request, FuelStore $store): JsonResponse
    {
        abort_unless($this->access->sees($request->user()) || $this->issuer($request->user()), 403);

        return response()->json(['data' => $store->movements()->with('creator')->limit(500)->get()->map(fn (FuelMovement $m) => ['id' => $m->id, 'date' => $m->date->toDateString(), 'type' => $m->type,
            'type_label' => FuelMovement::TYPES[$m->type] ?? $m->type, 'litres' => $m->litres, 'unit_price' => $m->unit_price, 'amount' => $m->amount, 'note' => $m->note,
            'by' => $m->creator?->display_name])->values(), 'balance' => $store->balance()]);
    }

    /** @response array{data: array{id: int}} */
    public function storeStore(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $s = $this->fuel->saveStore(null, $request->validate([
            'name' => ['required', 'string', 'max:100'],
            /** own (tank in stock) or station (filling station account paid in batches). */
            'kind' => ['required', 'in:own,station'],
            'fuel_type' => ['required', 'in:petrol,diesel,gas'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'account_reference' => ['nullable', 'string', 'max:60'],
            /** Station accounts: usual price per litre. */
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['data' => ['id' => $s->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateStore(Request $request, FuelStore $store): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $s = $this->fuel->saveStore($store, $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'in:own,station'],
            'fuel_type' => ['required', 'in:petrol,diesel,gas'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'account_reference' => ['nullable', 'string', 'max:60'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['data' => ['id' => $s->id]]);
    }

    /** @response array{data: array{id: int, number: string, payment_request_id: int|null}} */
    public function recharge(Request $request, FuelStore $store): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'date' => ['required', 'date'],
            'litres' => ['required', 'numeric', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0.01'],
            /** Department and budget line the payment request is charged to. */
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
        ]);
        $rc = $this->fuel->recharge($store, $data, $request->user());

        return response()->json(['data' => ['id' => $rc->id, 'number' => $rc->number, 'payment_request_id' => $rc->payment_request_id]], 201);
    }

    /** @response array{message: string} */
    public function receive(Request $request, FuelRecharge $recharge): JsonResponse
    {
        abort_unless($this->issuer($request->user()), 403);
        $data = $request->validate([
            /** Litres actually delivered (dip before and after). */
            'litres' => ['required', 'numeric', 'min:0.1'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'delivery_note' => ['nullable', 'string', 'max:60'],
        ]);
        $this->fuel->receive($recharge, $data, $request->user());

        return response()->json(['message' => 'Delivery recorded.']);
    }

    /** @response array{message: string} */
    public function cancelRecharge(Request $request, FuelRecharge $recharge): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $this->fuel->cancelRecharge($recharge);

        return response()->json(['message' => 'Order cancelled. Cancel its payment request in Payments too.']);
    }

    /** @response array{message: string} */
    public function adjust(Request $request, FuelStore $store): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            /** Litres measured in the tank. */
            'counted' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->fuel->adjust($store, (float) $data['counted'], $data['date'], $data['reason'], $request->user());

        return response()->json(['message' => 'Stock adjusted.']);
    }

    /** @response array{data: array{id: int, number: string, payment_request_id: int|null}} */
    public function batch(Request $request, FuelStore $store): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
        ]);
        $b = $this->fuel->stationBatch($store, $data, $request->user());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'payment_request_id' => $b->payment_request_id]], 201);
    }

    /**
     * Fuel use per unit in a period: litres, cost, distance and consumption, with requests flagged above the usual.
     *
     * @response array{data: list<array{unit_id: int, unit: string, kind: string, reading_unit: string, fills: int, litres: float, amount: float, distance: float, per_litre: float|null, cost_per_unit: float|null, flagged: int}>, from: string, to: string}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    public function consumption(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $from = $request->query('from') ?: today()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: today()->toDateString();
        $issued = FuelRequest::with('unit')->where('status', 'issued')->whereDate('issued_at', '>=', $from)->whereDate('issued_at', '<=', $to)->get()->groupBy('fleet_unit_id');
        $readings = Reading::where('status', 'approved')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->whereIn('fleet_unit_id', $issued->keys())->get()->groupBy('fleet_unit_id');
        $rows = $issued->map(function ($g, $unitId) use ($readings) {
            $unit = $g->first()->unit;
            $r = $readings[$unitId] ?? collect();
            $distance = $r->count() ? round($r->max('reading') - min($r->min('reading'), $r->min('previous') ?? $r->min('reading')), 1) : 0;
            $litres = round($g->sum('issued_litres'), 2);
            $amount = round($g->sum('amount'), 2);

            return ['unit_id' => (int) $unitId, 'unit' => (string) $unit?->label(), 'kind' => (string) $unit?->kind, 'reading_unit' => (string) $unit?->unit(), 'fills' => $g->count(), 'litres' => $litres,
                'amount' => $amount, 'distance' => $distance, 'per_litre' => $litres > 0 && $distance > 0 ? round($distance / $litres, 2) : null,
                'cost_per_unit' => $distance > 0 ? round($amount / $distance, 2) : null, 'flagged' => $g->filter(fn ($x) => ! empty($x->warnings))->count()];
        })->sortByDesc('amount')->values();

        return response()->json(['data' => $rows, 'from' => $from, 'to' => $to]);
    }

    private function issuer(User $user): bool
    {
        return $this->perms->can($user, 'fleet.fuel') || $this->access->manages($user);
    }

    /** @return list<int> units the user drives now */
    private function myUnits(User $user): array
    {
        $driver = $user->employee ? Driver::where('employee_id', $user->employee->id)->value('id') : null;

        return $driver ? Assignment::where('fleet_driver_id', $driver)->whereNull('ended_on')->pluck('fleet_unit_id')->map(fn ($v) => (int) $v)->all() : [];
    }
}
