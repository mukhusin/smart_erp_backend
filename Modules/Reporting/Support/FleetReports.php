<?php

namespace Modules\Reporting\Support;

use Illuminate\Support\Carbon;
use Modules\Fleet\Actions\ManageFuel;
use Modules\Fleet\Models\Accident;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Cost;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\FuelMovement;
use Modules\Fleet\Models\FuelRequest;
use Modules\Fleet\Models\FuelStore;
use Modules\Fleet\Models\Hire;
use Modules\Fleet\Models\Maintenance;
use Modules\Fleet\Models\Reading;
use Modules\Fleet\Models\TransportRequest;
use Modules\Fleet\Models\Trip;

/** Fleet reports, run through the finance report catalogue (group Fleet). */
trait FleetReports
{
    private function fleetFilters(string $key, array $from, array $to): ?array
    {
        return match ($key) {
            'fleet-register' => [['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(FleetUnit::STATUSES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'fleet-costs', 'fleet-fuel', 'fleet-utilisation', 'maintenance-history', 'fleet-accidents', 'fleet-hires' => [$from, $to],
            'transport-requests' => [$from, $to, ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(TransportRequest::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'fuel-store-movements' => [['key' => 'store_id', 'label' => 'Tank', 'type' => 'select', 'default' => (string) (FuelStore::where('kind', 'own')->orderBy('name')->value('id') ?? ''),
                'options' => FuelStore::where('kind', 'own')->orderBy('name')->get()->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->all()], $from, $to],
            'fleet-compliance' => [['key' => 'days', 'label' => 'Expiring within', 'type' => 'select', 'default' => '60', 'options' => [['value' => '30', 'label' => '30 days'], ['value' => '60', 'label' => '60 days'],
                ['value' => '90', 'label' => '90 days'], ['value' => '3650', 'label' => 'All current']]]],
            default => null,
        };
    }

    private function period(array $v): string
    {
        return "{$this->d($v['from'])} to {$this->d($v['to'])}";
    }

    /** Distance moved by each unit in the period, from its readings. @return array<int, float> */
    private function distances(array $unitIds, string $from, string $to): array
    {
        return Reading::where('status', 'approved')->whereIn('fleet_unit_id', $unitIds)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->groupBy('fleet_unit_id')
            ->map(fn ($g) => round($g->max('reading') - min($g->min('reading'), $g->whereNotNull('previous')->min('previous') ?? $g->min('reading')), 1))->all();
    }

    private function reportFleetRegister(array $v): array
    {
        $rows = FleetUnit::with(['type', 'department', 'activeAssignment.driver.employee', 'compliance'])->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'disposed'))
            ->orderBy('kind')->orderBy('registration')->get()->map(fn ($u) => ['registration' => $u->registration ?? '—', 'name' => $u->name, 'type' => $u->type?->name, 'kind' => FleetUnit::KINDS[$u->kind] ?? $u->kind,
                'ownership' => FleetUnit::OWNERSHIP[$u->ownership] ?? $u->ownership, 'department' => $u->department?->name, 'driver' => $u->activeAssignment?->driver?->name(),
                'reading' => $u->reading.' '.$u->unit(), 'insurance' => $u->compliance->where('is_current', true)->firstWhere('type', 'insurance')?->expires_on?->format('j M Y') ?? 'None',
                'status' => FleetUnit::STATUSES[$u->status] ?? $u->status]);

        return ["{$rows->count()} unit(s)", ['registration', 'name', 'type', 'kind', 'ownership', 'department', 'driver', 'reading', 'insurance', 'status'], $rows->all(),
            ['insurance' => 'Insurance expires']];
    }

    private function reportFleetCosts(array $v): array
    {
        $costs = Cost::with('unit')->whereHas('unit')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->get()->groupBy('fleet_unit_id');
        $dist = $this->distances($costs->keys()->all(), $v['from'], $v['to']);
        $cats = array_keys(Cost::CATEGORIES);
        $rows = $costs->map(function ($g, $id) use ($dist, $cats) {
            $row = ['unit' => $g->first()->unit->label()];
            foreach ($cats as $c) {
                $row[$c] = round($g->where('category', $c)->sum('amount'), 2);
            }
            $row['total'] = round($g->sum('amount'), 2);
            $row['distance'] = $dist[$id] ?? 0;
            $row['per_km'] = ($dist[$id] ?? 0) > 0 ? round($row['total'] / $dist[$id], 2) : null;

            return $row;
        })->sortByDesc('total')->values();
        $total = ['unit' => 'Total'];
        foreach ([...$cats, 'total'] as $c) {
            $total[$c] = round($rows->sum($c), 2);
        }
        $rows->push($total);

        return [$this->period($v), ['unit', ...$cats, 'total', 'distance', 'per_km'], $rows->all(), collect(Cost::CATEGORIES)->all() + ['per_km' => 'Cost per km / hour', 'distance' => 'Distance']];
    }

    private function reportFleetFuel(array $v): array
    {
        $issued = FuelRequest::with('unit')->where('status', 'issued')->whereDate('issued_at', '>=', $v['from'])->whereDate('issued_at', '<=', $v['to'])->get()->groupBy('fleet_unit_id');
        $dist = $this->distances($issued->keys()->all(), $v['from'], $v['to']);
        $fuel = app(ManageFuel::class);
        $rows = $issued->map(function ($g, $id) use ($dist, $fuel) {
            $unit = $g->first()->unit;
            $litres = round($g->sum('issued_litres'), 2);
            $usual = $fuel->consumption($unit)['per_litre'];

            return ['unit' => $unit->label(), 'fills' => $g->count(), 'litres' => $litres, 'amount' => round($g->sum('amount'), 2), 'distance' => $dist[$id] ?? 0,
                'per_litre' => $litres > 0 && ($dist[$id] ?? 0) > 0 ? round($dist[$id] / $litres, 2) : null, 'usual' => $usual, 'flagged' => $g->filter(fn ($x) => ! empty($x->warnings))->count()];
        })->sortByDesc('amount')->values();
        $rows->push(['unit' => 'Total', 'fills' => $rows->sum('fills'), 'litres' => round($rows->sum('litres'), 2), 'amount' => round($rows->sum('amount'), 2), 'flagged' => $rows->sum('flagged')]);

        return [$this->period($v), ['unit', 'fills', 'litres', 'amount', 'distance', 'per_litre', 'usual', 'flagged'], $rows->all(),
            ['per_litre' => 'km (or hours) per litre', 'usual' => 'Usual per litre', 'flagged' => 'Fills above usual', 'litres' => 'Litres', 'fills' => 'Fills']];
    }

    private function reportFuelStoreMovements(array $v): array
    {
        $store = FuelStore::find($v['store_id'] ?? null);
        if (! $store) {
            throw new MissingChoice('Choose a tank.');
        }
        $opening = round((float) $store->movements()->reorder()->whereDate('date', '<', $v['from'])->sum('litres'), 2);
        $balance = $opening;
        $rows = collect([['date' => $this->d($v['from']), 'type' => 'Opening balance', 'litres' => null, 'amount' => null, 'balance' => $opening]]);
        foreach ($store->movements()->reorder('date')->orderBy('id')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->get() as $m) {
            $balance = round($balance + $m->litres, 2);
            $rows->push(['date' => $this->d($m->date->toDateString()), 'type' => FuelMovement::TYPES[$m->type] ?? $m->type, 'note' => $m->note, 'litres' => $m->litres, 'unit_price' => $m->unit_price,
                'amount' => $m->amount, 'balance' => $balance]);
        }

        return ["{$store->name}, {$this->period($v)}", ['date', 'type', 'note', 'litres', 'unit_price', 'amount', 'balance'], $rows->all(), ['litres' => 'Litres', 'balance' => 'Balance (L)', 'unit_price' => 'Price']];
    }

    private function reportTransportRequests(array $v): array
    {
        $rows = TransportRequest::with(['department', 'requester', 'trips.unit', 'trips.contract.supplier'])->whereDate('departs_at', '>=', $v['from'])->whereDate('departs_at', '<=', $v['to'])
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderBy('departs_at')->get()
            ->map(fn ($r) => ['number' => $r->number, 'date' => $r->departs_at->format('j M Y H:i'), 'department' => $r->department?->name, 'requested_by' => $r->requester?->display_name,
                'purpose' => $r->purpose, 'destination' => $r->destination, 'passengers' => $r->passengers,
                'vehicle' => $r->trips->where('status', '!=', 'cancelled')->map(fn ($t) => $t->unit?->label() ?? $t->contract?->label())->filter()->join(', '),
                'status' => TransportRequest::STATUS_LABELS[$r->status] ?? $r->status]);

        return [$this->period($v)." · {$rows->count()} request(s)", ['number', 'date', 'department', 'requested_by', 'purpose', 'destination', 'passengers', 'vehicle', 'status'], $rows->all(),
            ['passengers' => 'People', 'requested_by' => 'Asked by']];
    }

    private function reportFleetUtilisation(array $v): array
    {
        $from = Carbon::parse($v['from'])->startOfDay();
        $to = Carbon::parse($v['to'])->endOfDay();
        $days = max(1, (int) $from->diffInDays($to) + 1);
        $trips = Trip::whereNotNull('fleet_unit_id')->whereIn('status', ['started', 'completed', 'confirmed'])->where('departs_at', '<=', $to)->where('returns_at', '>=', $from)->get()->groupBy('fleet_unit_id');
        $units = FleetUnit::where('kind', 'vehicle')->where('status', '!=', 'disposed')->orderBy('registration')->get();
        $dist = $this->distances($units->pluck('id')->all(), $v['from'], $v['to']);
        $rows = $units->map(function ($u) use ($trips, $from, $to, $days, $dist) {
            $g = $trips[$u->id] ?? collect();
            $busy = $g->flatMap(function ($t) use ($from, $to) {
                $a = Carbon::parse(max($t->started_at ?? $t->departs_at, $from))->startOfDay();
                $b = Carbon::parse(min($t->ended_at ?? $t->returns_at, $to))->startOfDay();

                return $a->lte($b) ? collect(range(0, (int) $a->diffInDays($b)))->map(fn ($i) => $a->copy()->addDays($i)->toDateString()) : collect();
            })->unique()->count();

            return ['unit' => $u->label(), 'status' => FleetUnit::STATUSES[$u->status] ?? $u->status, 'trips' => $g->count(), 'days_used' => $busy,
                'use' => round($busy / $days * 100, 1).'%', 'trip_km' => round($g->sum(fn ($t) => $t->distance() ?? 0), 1), 'distance' => $dist[$u->id] ?? 0];
        })->sortByDesc('days_used')->values();

        return [$this->period($v)." ({$days} days)", ['unit', 'status', 'trips', 'days_used', 'use', 'trip_km', 'distance'], $rows->all(),
            ['days_used' => 'Days on trips', 'use' => 'Use', 'trip_km' => 'Km on logbooks', 'distance' => 'Km on the odometer']];
    }

    private function reportMaintenanceHistory(array $v): array
    {
        $rows = Maintenance::with(['unit', 'supplier'])->where(fn ($q) => $q->whereBetween('completed_on', [$v['from'], $v['to']])
            ->orWhere(fn ($q) => $q->whereIn('status', Maintenance::OPEN)->whereDate('created_at', '<=', $v['to'])))->orderBy('fleet_unit_id')->orderBy('id')->get()
            ->map(fn ($m) => ['number' => $m->number, 'unit' => $m->unit?->label(), 'kind' => Maintenance::KINDS[$m->kind] ?? $m->kind, 'work' => mb_strimwidth($m->findings ?: $m->description, 0, 120, '…'),
                'workshop' => $m->supplier?->name ?? $m->workshop, 'started' => $m->started_on ? $this->d($m->started_on->toDateString()) : null,
                'completed' => $m->completed_on ? $this->d($m->completed_on->toDateString()) : null, 'cost' => $m->actual_cost ?? $m->estimate, 'status' => Maintenance::STATUS_LABELS[$m->status] ?? $m->status]);
        $rows->push(['number' => 'Total', 'cost' => round($rows->sum('cost'), 2)]);

        return [$this->period($v).' · done in the period and still open', ['number', 'unit', 'kind', 'work', 'workshop', 'started', 'completed', 'cost', 'status'], $rows->all()];
    }

    private function reportFleetAccidents(array $v): array
    {
        $rows = Accident::with(['unit', 'driver.employee', 'maintenance'])->whereDate('happened_at', '>=', $v['from'])->whereDate('happened_at', '<=', $v['to'])->orderBy('happened_at')->get()
            ->map(fn ($a) => ['number' => $a->number, 'date' => $a->happened_at->format('j M Y H:i'), 'unit' => $a->unit?->label(), 'driver' => $a->driver?->name(), 'location' => $a->location,
                'injuries' => $a->injuries ? 'Yes' : 'No', 'police_report' => $a->police_report, 'outcome' => $a->outcome ? (Accident::OUTCOMES[$a->outcome] ?? $a->outcome) : 'Not assessed',
                'repair_cost' => $a->maintenance?->actual_cost, 'insurance_claim' => $a->insurance_claim]);

        return [$this->period($v)." · {$rows->count()} accident(s)", ['number', 'date', 'unit', 'driver', 'location', 'injuries', 'police_report', 'outcome', 'repair_cost', 'insurance_claim'], $rows->all(),
            ['police_report' => 'Police report', 'repair_cost' => 'Repair cost', 'insurance_claim' => 'Insurance claim']];
    }

    private function reportFleetCompliance(array $v): array
    {
        $until = today()->addDays((int) ($v['days'] ?: 60));
        $items = Compliance::with(['unit', 'supplier'])->where('is_current', true)->whereHas('unit', fn ($q) => $q->where('status', '!=', 'disposed'))->whereDate('expires_on', '<=', $until)->get()
            ->map(fn ($c) => ['for' => $c->unit->label(), 'document' => Compliance::TYPES[$c->type] ?? $c->type, 'reference' => $c->reference, 'provider' => $c->provider ?? $c->supplier?->name,
                'expires' => $this->d($c->expires_on->toDateString()), 'days_left' => (int) today()->diffInDays($c->expires_on, false), 'sort' => $c->expires_on->toDateString()]);
        $licences = Driver::with(['employee', 'licences'])->where('status', 'active')->get()->map(fn ($d) => [$d, $d->currentLicence()])->filter(fn ($x) => $x[1] && $x[1]->end_date->lte($until))
            ->map(fn ($x) => ['for' => $x[0]->name(), 'document' => 'Driving licence', 'reference' => $x[1]->licence_no, 'provider' => null, 'expires' => $this->d($x[1]->end_date->toDateString()),
                'days_left' => (int) today()->diffInDays($x[1]->end_date, false), 'sort' => $x[1]->end_date->toDateString()]);
        $rows = $items->concat($licences)->sortBy('sort')->map(fn ($r) => collect($r)->except('sort')->all() + ['state' => $r['days_left'] < 0 ? 'Expired' : 'Valid'])->values();

        return ["As at {$this->d(today()->toDateString())}", ['for', 'document', 'reference', 'provider', 'expires', 'days_left', 'state'], $rows->all(), ['days_left' => 'Days left', 'state' => 'Status']];
    }

    private function reportFleetHires(array $v): array
    {
        $rows = Hire::with(['supplier', 'units', 'payments'])->whereDate('starts_on', '<=', $v['to'])->where(fn ($q) => $q->whereDate('ends_on', '>=', $v['from']))->orderBy('starts_on')->get()
            ->map(fn ($h) => ['number' => $h->number, 'supplier' => $h->supplier?->name, 'vehicles' => $h->units->count(), 'from' => $this->d($h->starts_on->toDateString()),
                'to' => $this->d($h->lastDay()->toDateString()), 'rate' => number_format($h->rate, 2).' '.(Hire::MODES[$h->rate_mode] ?? ''), 'paid' => round($h->payments->sum('amount'), 2),
                'paid_until' => $h->payments->max(fn ($p) => $p->to_date->toDateString()), 'status' => Hire::STATUS_LABELS[$h->status] ?? $h->status]);
        $rows->push(['number' => 'Total', 'paid' => round($rows->sum('paid'), 2)]);

        return [$this->period($v), ['number', 'supplier', 'vehicles', 'from', 'to', 'rate', 'paid', 'paid_until', 'status'], $rows->all(), ['paid_until' => 'Paid until']];
    }
}
