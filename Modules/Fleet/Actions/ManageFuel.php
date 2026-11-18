<?php

namespace Modules\Fleet\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\FuelBatch;
use Modules\Fleet\Models\FuelRecharge;
use Modules\Fleet\Models\FuelRequest;
use Modules\Fleet\Models\FuelStore;
use Modules\Fleet\Workflow\FuelRequestProcess;
use Modules\Payments\Actions\ManageRequests;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageFuel
{
    public const TOLERANCE = 1.25;

    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageUnits $units, private readonly ManageRequests $requests) {}

    public function saveStore(?FuelStore $store, array $data): FuelStore
    {
        if (($data['kind'] ?? 'own') === 'station' && empty($data['supplier_id'])) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose the filling station from the supplier register.']);
        }
        $store ??= new FuelStore;
        $store->fill(collect($data)->only(['name', 'kind', 'fuel_type', 'capacity', 'reorder_level', 'supplier_id', 'account_reference', 'price'])->all() + ['is_active' => (bool) ($data['is_active'] ?? true)])->save();

        return $store->refresh();
    }

    /**
     * Usual consumption of a unit from its last fills: km (or hours) per litre.
     *
     * @return array{per_litre: float|null, last_reading: float|null, fills: int}
     */
    public function consumption(FleetUnit $unit): array
    {
        $fills = FuelRequest::where('fleet_unit_id', $unit->id)->where('status', 'issued')->whereNotNull('issue_reading')->orderByDesc('issue_reading')->limit(7)->get()->reverse()->values();
        $distance = 0.0;
        $litres = 0.0;
        for ($i = 1; $i < $fills->count(); $i++) {
            $d = $fills[$i]->issue_reading - $fills[$i - 1]->issue_reading;
            if ($d > 0 && $fills[$i]->issued_litres > 0) {
                $distance += $d;
                $litres += $fills[$i]->issued_litres;
            }
        }

        return ['per_litre' => $litres > 0 ? round($distance / $litres, 2) : null, 'last_reading' => $fills->last()?->issue_reading, 'fills' => $fills->count()];
    }

    /** @return array{hard: list<string>, soft: list<string>} */
    public function check(FleetUnit $unit, FuelStore $store, float $litres, float $reading, ?FuelRequest $except = null): array
    {
        $hard = [];
        $soft = [];
        $u = $unit->unit();
        if ($unit->status !== 'operational') {
            $hard[] = "{$unit->label()} is ".strtolower(FleetUnit::STATUSES[$unit->status] ?? $unit->status).'; only operational units are fuelled.';
        }
        if (! $store->is_active) {
            $hard[] = "{$store->name} is closed.";
        }
        if ($unit->fuel_type && $unit->fuel_type !== $store->fuel_type) {
            $hard[] = "{$unit->label()} uses ".strtolower(FleetUnit::FUELS[$unit->fuel_type] ?? $unit->fuel_type)."; {$store->name} has ".strtolower(FleetUnit::FUELS[$store->fuel_type] ?? $store->fuel_type).'.';
        }
        $tank = $unit->tank_capacity ?: $unit->type?->tank_capacity;
        if ($tank && $litres > $tank) {
            $hard[] = "{$litres} L is more than the {$tank} L tank of {$unit->label()}.";
        }
        if ($reading < $unit->reading) {
            $hard[] = "The reading cannot be below the last one (".number_format($unit->reading, 1)." {$u}).";
        }
        if ($open = FuelRequest::where('fleet_unit_id', $unit->id)->whereIn('status', ['pending', 'approved'])->when($except, fn ($q) => $q->where('id', '!=', $except->id))->value('number')) {
            $hard[] = "Fuel request {$open} of {$unit->label()} is still open.";
        }
        $c = $this->consumption($unit);
        if ($c['per_litre'] && $c['last_reading'] !== null) {
            $since = max(0, $reading - $c['last_reading']);
            $expected = $since / $c['per_litre'];
            if ($litres > $expected * self::TOLERANCE + 5) {
                $soft[] = "Asked {$litres} L but ".number_format($since, 0)." {$u} since the last fill at about {$c['per_litre']} {$u}/L uses about ".number_format($expected, 0).' L.';
            }
        }
        if ($store->isOwn() && $litres > $store->balance() - $store->committed() + ($except?->status === 'approved' ? $except->litres : 0)) {
            $soft[] = "{$store->name} has ".number_format(max(0, $store->balance() - $store->committed()), 0).' L free.';
        }

        return ['hard' => $hard, 'soft' => $soft];
    }

    public function request(array $data, User $by): FuelRequest
    {
        $unit = FleetUnit::with('type')->findOrFail($data['fleet_unit_id']);
        $store = FuelStore::findOrFail($data['fleet_fuel_store_id']);
        $litres = round((float) $data['litres'], 2);
        $reading = round((float) $data['reading'], 1);
        $problems = $this->check($unit, $store, $litres, $reading);
        if ($problems['hard']) {
            throw ValidationException::withMessages(['litres' => implode(' ', $problems['hard'])]);
        }
        $driver = $by->employee ? Driver::where('employee_id', $by->employee->id)->value('id') : null;

        return DB::transaction(function () use ($data, $by, $unit, $store, $litres, $reading, $problems, $driver) {
            $r = FuelRequest::create(['number' => Numbering::next(FuelRequest::class, 'FR'), 'fleet_unit_id' => $unit->id, 'fleet_driver_id' => $driver, 'fleet_trip_id' => $data['fleet_trip_id'] ?? null,
                'fleet_fuel_store_id' => $store->id, 'department_id' => $data['department_id'] ?? $unit->department_id ?? $by->employee?->department_id, 'litres' => $litres, 'reading' => $reading,
                'purpose' => $data['purpose'] ?? null, 'warnings' => $problems['soft'] ?: null, 'status' => 'pending', 'requested_by' => $by->id]);
            if (! $this->workflow->start(FuelRequestProcess::KEY, $r, $by)) {
                $this->approved($r);
            }

            return $r->refresh();
        });
    }

    public function approved(FuelRequest $r): void
    {
        $r->update(['status' => 'approved']);
        $r->requester?->notify(new Notice('Fuel approved', "{$r->number}: {$r->litres} L for {$r->unit->label()} from {$r->store->name}.", "/fleet/fuel/{$r->id}", 'fuel-pump'));
    }

    public function rejected(FuelRequest $r, ?string $comment, bool $withdrawn): void
    {
        $r->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment]);
        if (! $withdrawn) {
            $r->requester?->notify(new Notice('Fuel not approved', "{$r->number}: {$comment}", "/fleet/fuel/{$r->id}", 'fuel-pump'));
        }
    }

    public function withdraw(FuelRequest $r, User $by): void
    {
        if ($r->status !== 'pending' || $r->requested_by !== $by->id) {
            throw ValidationException::withMessages(['request' => 'Only your request waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($r, null, true);
    }

    public function cancel(FuelRequest $r, string $reason): void
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['request' => 'Only an approved request not yet issued can be cancelled.']);
        }
        $r->update(['status' => 'cancelled', 'decision_comment' => $reason]);
    }

    /** Issue what was approved, or less (short supply); the rest is released. */
    public function issue(FuelRequest $r, array $data, User $by): FuelRequest
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['request' => 'Only an approved request can be issued.']);
        }
        if ($r->requested_by === $by->id) {
            throw ValidationException::withMessages(['request' => 'The person who asked for the fuel cannot issue it.']);
        }
        $r->load(['store', 'unit']);
        $store = $r->store;
        $litres = round((float) $data['litres'], 2);
        $reading = round((float) $data['reading'], 1);
        if ($litres <= 0 || $litres > $r->litres) {
            throw ValidationException::withMessages(['litres' => "Issue at most the {$r->litres} L approved."]);
        }
        if ($reading < $r->unit->reading) {
            throw ValidationException::withMessages(['reading' => 'The reading cannot be below the last one ('.number_format($r->unit->reading, 1).').']);
        }
        if ($store->isOwn() && $litres > $store->balance()) {
            throw ValidationException::withMessages(['litres' => "{$store->name} has only ".number_format($store->balance(), 2).' L.']);
        }
        $price = $store->isOwn() ? $store->averagePrice() : round((float) ($data['unit_price'] ?? $store->price ?? 0), 2);
        if (! $store->isOwn() && $price <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'Enter the price per litre on the station receipt.']);
        }
        if (! $store->isOwn() && empty($data['receipt_reference'])) {
            throw ValidationException::withMessages(['receipt_reference' => 'Enter the number of the station receipt.']);
        }
        $at = Carbon::parse($data['issued_at'] ?? now());

        return DB::transaction(function () use ($r, $store, $litres, $reading, $price, $at, $data, $by) {
            $amount = round($litres * $price, 2);
            if ($store->isOwn()) {
                $store->movements()->create(['date' => $at->toDateString(), 'type' => 'issue', 'litres' => -$litres, 'unit_price' => $price, 'amount' => -$amount,
                    'fleet_fuel_request_id' => $r->id, 'note' => "{$r->number} {$r->unit->label()}", 'created_by' => $by->id]);
            }
            if ($reading > $r->unit->reading) {
                $this->units->addReading($r->unit, $reading, max($at->toDateString(), $r->unit->reading_date?->toDateString() ?? $at->toDateString()), 'fuel', $r->number, $by, $r);
            }
            $r->unit->costs()->create(['date' => $at->toDateString(), 'category' => 'fuel', 'amount' => $amount, 'description' => "{$litres} L from {$store->name}",
                'document_type' => $r->getMorphClass(), 'document_id' => $r->id, 'created_by' => $by->id]);
            $r->update(['status' => 'issued', 'issued_litres' => $litres, 'issue_reading' => $reading, 'unit_price' => $price, 'amount' => $amount,
                'receipt_reference' => $data['receipt_reference'] ?? null, 'issued_at' => $at, 'issued_by' => $by->id]);

            return $r->refresh();
        });
    }

    /** Orders fuel for an own tank: a draft payment request to the supplier is prepared. */
    public function recharge(FuelStore $store, array $data, User $by): FuelRecharge
    {
        if (! $store->isOwn()) {
            throw ValidationException::withMessages(['store' => 'Only an own tank is refilled; a station account is paid in batches.']);
        }
        $litres = round((float) $data['litres'], 2);
        if ($store->capacity && $store->balance() + $litres > $store->capacity) {
            throw ValidationException::withMessages(['litres' => "{$store->name} holds {$store->capacity} L and has ".number_format($store->balance(), 0).' L.']);
        }
        $amount = round($litres * (float) $data['unit_price'], 2);
        $supplier = Supplier::with(['bankAccounts' => fn ($q) => $q->where('status', 'approved')->orderByDesc('is_default')])->findOrFail($data['supplier_id']);
        $bank = $supplier->bankAccounts->first();

        return DB::transaction(function () use ($store, $data, $by, $litres, $amount, $supplier, $bank) {
            $rc = FuelRecharge::create(['number' => Numbering::next(FuelRecharge::class, 'FRC', $data['date']), 'fleet_fuel_store_id' => $store->id, 'supplier_id' => $supplier->id,
                'date' => $data['date'], 'litres' => $litres, 'unit_price' => round((float) $data['unit_price'], 2), 'amount' => $amount, 'status' => 'ordered', 'created_by' => $by->id]);
            $pr = $this->requests->save(null, ['department_id' => $data['department_id'], 'title' => mb_substr("Fuel {$litres} L for {$store->name}", 0, 200), 'purpose' => "Fuel order {$rc->number}",
                'lines' => [['budget_item_id' => $data['budget_item_id'], 'description' => "Fuel {$litres} L", 'amount' => $amount]],
                'payees' => [['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
                    'account_number' => $bank?->account_number, 'amount' => $amount]]], $by);
            $pr->update(['supplier_id' => $supplier->id, 'source_type' => $rc->getMorphClass(), 'source_id' => $rc->id]);
            $rc->update(['payment_request_id' => $pr->id]);

            return $rc->refresh();
        });
    }

    public function receive(FuelRecharge $rc, array $data, User $by): FuelRecharge
    {
        if ($rc->status !== 'ordered') {
            throw ValidationException::withMessages(['recharge' => 'Only an order not yet delivered can be received.']);
        }
        $litres = round((float) $data['litres'], 2);
        $store = $rc->store;
        if ($store->capacity && $store->balance() + $litres > $store->capacity) {
            throw ValidationException::withMessages(['litres' => "{$store->name} holds {$store->capacity} L; it has ".number_format($store->balance(), 0).' L.']);
        }
        DB::transaction(function () use ($rc, $data, $by, $litres, $store) {
            $store->movements()->create(['date' => $data['date'], 'type' => 'recharge', 'litres' => $litres, 'unit_price' => $rc->unit_price, 'amount' => round($litres * $rc->unit_price, 2),
                'fleet_fuel_recharge_id' => $rc->id, 'note' => "{$rc->number} {$rc->supplier?->name}".(! empty($data['delivery_note']) ? " ({$data['delivery_note']})" : ''), 'created_by' => $by->id]);
            $rc->update(['status' => 'received', 'received_litres' => $litres, 'received_on' => $data['date'], 'delivery_note' => $data['delivery_note'] ?? null]);
        });

        return $rc->refresh();
    }

    public function cancelRecharge(FuelRecharge $rc): void
    {
        if ($rc->status !== 'ordered') {
            throw ValidationException::withMessages(['recharge' => 'Only an order not yet delivered can be cancelled.']);
        }
        $rc->update(['status' => 'cancelled']);
    }

    /** Stock count of an own tank: the difference is booked at the average price. */
    public function adjust(FuelStore $store, float $counted, string $date, string $reason, User $by): void
    {
        if (! $store->isOwn()) {
            throw ValidationException::withMessages(['store' => 'Only an own tank is counted.']);
        }
        $diff = round($counted - $store->balance(), 2);
        if ($diff == 0) {
            throw ValidationException::withMessages(['litres' => 'The count is the same as the balance.']);
        }
        $price = $store->averagePrice();
        $store->movements()->create(['date' => $date, 'type' => 'adjust', 'litres' => $diff, 'unit_price' => $price, 'amount' => round($diff * $price, 2), 'note' => $reason, 'created_by' => $by->id]);
    }

    /** Fuel taken on credit at a station in a period: one draft payment request to the station. */
    public function stationBatch(FuelStore $store, array $data, User $by): FuelBatch
    {
        if ($store->isOwn()) {
            throw ValidationException::withMessages(['store' => 'Only a station account is paid in batches.']);
        }
        $requests = $store->requests()->where('status', 'issued')->whereNull('fleet_fuel_batch_id')->whereDate('issued_at', '>=', $data['from_date'])->whereDate('issued_at', '<=', $data['to_date'])->get();
        if ($requests->isEmpty()) {
            throw ValidationException::withMessages(['from_date' => 'No fuel taken at this station in that period is waiting for payment.']);
        }
        $total = round($requests->sum('amount'), 2);
        $litres = round($requests->sum('issued_litres'), 2);
        $supplier = Supplier::with(['bankAccounts' => fn ($q) => $q->where('status', 'approved')->orderByDesc('is_default')])->findOrFail($store->supplier_id);
        $bank = $supplier->bankAccounts->first();

        return DB::transaction(function () use ($store, $data, $by, $requests, $total, $litres, $supplier, $bank) {
            $b = FuelBatch::create(['number' => Numbering::next(FuelBatch::class, 'FB'), 'fleet_fuel_store_id' => $store->id, 'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
                'litres' => $litres, 'total' => $total, 'created_by' => $by->id]);
            $period = Carbon::parse($data['from_date'])->format('d M').' – '.Carbon::parse($data['to_date'])->format('d M Y');
            $pr = $this->requests->save(null, ['department_id' => $data['department_id'], 'title' => mb_substr("Fuel at {$supplier->name} {$period}", 0, 200),
                'purpose' => "Batch {$b->number}: {$requests->count()} fill(s), {$litres} L",
                'lines' => [['budget_item_id' => $data['budget_item_id'], 'description' => "Fuel batch {$b->number}", 'amount' => $total]],
                'payees' => [['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
                    'account_number' => $bank?->account_number, 'amount' => $total]]], $by);
            $pr->update(['supplier_id' => $supplier->id, 'source_type' => $b->getMorphClass(), 'source_id' => $b->id]);
            $b->update(['payment_request_id' => $pr->id]);
            FuelRequest::whereIn('id', $requests->pluck('id'))->update(['fleet_fuel_batch_id' => $b->id]);

            return $b->refresh();
        });
    }
}
