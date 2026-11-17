<?php

namespace Modules\Fleet\Actions;

use App\Models\EmpLeave;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Fleet\Models\AgencyContract;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\TransportRequest;
use Modules\Fleet\Models\Trip;
use Modules\Fleet\Support\FleetAccess;
use Modules\Fleet\Workflow\TransportRequestProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageTransport
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageDrivers $drivers, private readonly ManageUnits $units, private readonly FleetAccess $access) {}

    public function request(array $data, User $by): TransportRequest
    {
        $this->checkTimes($data['departs_at'], $data['returns_at']);
        if (Carbon::parse($data['departs_at'])->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['departs_at' => 'Transport cannot be requested for a day that has passed.']);
        }
        $employee = $by->employee;

        return DB::transaction(function () use ($data, $by, $employee) {
            $r = TransportRequest::create(['number' => Numbering::next(TransportRequest::class, 'TR'), 'requested_by' => $by->id, 'employee_id' => $employee?->id,
                'department_id' => $data['department_id'] ?? $employee?->department_id, 'status' => 'pending'] + collect($data)->only(['purpose', 'from_location', 'destination', 'departs_at',
                    'returns_at', 'passengers', 'passenger_names', 'cargo', 'cargo_weight', 'vehicle_type_id'])->all());
            if (! $this->workflow->start(TransportRequestProcess::KEY, $r, $by)) {
                $this->approved($r);
            }

            return $r->refresh();
        });
    }

    public function approved(TransportRequest $r): void
    {
        $r->update(['status' => 'approved', 'decided_at' => now()]);
        $notice = new Notice('Transport to allocate', "{$r->number}: {$r->purpose} to {$r->destination}, ".$r->departs_at->format('d M Y H:i').'.', "/fleet/requests/{$r->id}", 'truck-front');
        foreach ($this->access->managers() as $user) {
            $user->notify($notice);
        }
    }

    public function rejected(TransportRequest $r, ?string $comment, bool $withdrawn): void
    {
        $r->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $r->requester?->notify(new Notice('Transport request not approved', "{$r->number}: {$comment}", "/self-service/transport/{$r->id}", 'truck-front'));
        }
    }

    public function withdraw(TransportRequest $r, User $by): void
    {
        if ($r->status !== 'pending' || $r->requested_by !== $by->id) {
            throw ValidationException::withMessages(['request' => 'Only your request waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($r, null, true);
    }

    public function cancel(TransportRequest $r, string $reason, User $by): void
    {
        if (! in_array($r->status, ['approved', 'allocated'], true) || $r->trips()->where('status', 'started')->exists()) {
            throw ValidationException::withMessages(['request' => 'Only an approved request whose trip has not started can be cancelled.']);
        }
        DB::transaction(function () use ($r, $reason) {
            $r->trips()->where('status', 'scheduled')->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            $r->update(['status' => 'cancelled', 'decision_comment' => $reason]);
        });
    }

    /** Transport cannot serve the request (no vehicle, wrong dates): it goes back to the requester. */
    public function giveBack(TransportRequest $r, string $reason): void
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['request' => 'Only an approved request without a trip can be returned.']);
        }
        $r->update(['status' => 'returned', 'decision_comment' => $reason]);
        $r->requester?->notify(new Notice('Transport request returned', "{$r->number}: {$reason}", "/self-service/transport/{$r->id}", 'truck-front'));
    }

    /**
     * Problems that stop the trip; those listed in `soft` may be overridden with a reason.
     *
     * @return array{hard: list<string>, soft: list<string>}
     */
    public function check(array $data, ?Trip $except = null): array
    {
        $hard = [];
        $soft = [];
        $from = Carbon::parse($data['departs_at']);
        $to = Carbon::parse($data['returns_at']);
        $overlap = fn ($q) => $q->whereIn('status', Trip::BUSY)->when($except, fn ($q) => $q->where('id', '!=', $except->id))
            ->where('departs_at', '<', $to)->where('returns_at', '>', $from);
        if (! empty($data['fleet_agency_contract_id'])) {
            $contract = AgencyContract::find($data['fleet_agency_contract_id']);
            if (! $contract || ! $contract->validOn($from->toDateString())) {
                $hard[] = 'The transport contract is not valid on the day of the trip.';
            }

            return ['hard' => $hard, 'soft' => $soft];
        }
        $unit = FleetUnit::with('type', 'compliance')->find($data['fleet_unit_id'] ?? null);
        $driver = Driver::with('employee')->find($data['fleet_driver_id'] ?? null);
        if (! $unit || $unit->kind !== 'vehicle') {
            return ['hard' => ['Choose a vehicle.'], 'soft' => []];
        }
        if (! $driver) {
            return ['hard' => ['Choose a driver.'], 'soft' => []];
        }
        if ($unit->status !== 'operational') {
            $hard[] = "{$unit->label()} is ".strtolower(FleetUnit::STATUSES[$unit->status] ?? $unit->status).'.';
        }
        if ($why = $this->drivers->ineligible($driver, $unit)) {
            $hard[] = $why;
        }
        if ($busy = Trip::where('fleet_unit_id', $unit->id)->where($overlap)->value('number')) {
            $hard[] = "{$unit->label()} is already booked for trip {$busy} at that time.";
        }
        if ($busy = Trip::where('fleet_driver_id', $driver->id)->where($overlap)->value('number')) {
            $hard[] = "{$driver->name()} already drives trip {$busy} at that time.";
        }
        foreach ($unit->compliance->where('is_current', true) as $c) {
            if ($c->expires_on->lt($to->copy()->startOfDay())) {
                $soft[] = (\Modules\Fleet\Models\Compliance::TYPES[$c->type] ?? $c->type).' of '.$unit->label().' expires '.$c->expires_on->format('d M Y').', before the trip ends.';
            }
        }
        if (! $unit->compliance->where('is_current', true)->where('type', 'insurance')->count()) {
            $soft[] = "{$unit->label()} has no insurance entered.";
        }
        if (EmpLeave::where('employee_id', $driver->employee_id)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->exists()) {
            $soft[] = "{$driver->name()} is on leave during the trip.";
        }
        if ($unit->seats && (int) ($data['passengers'] ?? 0) > $unit->seats) {
            $soft[] = "{$unit->label()} has {$unit->seats} seats for {$data['passengers']} passengers.";
        }

        return ['hard' => $hard, 'soft' => $soft];
    }

    public function dispatch(array $data, User $by): Trip
    {
        $this->checkTimes($data['departs_at'], $data['returns_at']);
        $r = ! empty($data['transport_request_id']) ? TransportRequest::find($data['transport_request_id']) : null;
        if ($r && $r->status !== 'approved') {
            throw ValidationException::withMessages(['transport_request_id' => 'Only an approved request waiting for a vehicle can be allocated.']);
        }
        $this->guard($this->check($data), $data['override_reason'] ?? null);

        return DB::transaction(function () use ($data, $by, $r) {
            $agency = ! empty($data['fleet_agency_contract_id']);
            $trip = Trip::create(['number' => Numbering::next(Trip::class, 'TRP'), 'transport_request_id' => $r?->id, 'status' => 'scheduled', 'created_by' => $by->id,
                'fleet_unit_id' => $agency ? null : $data['fleet_unit_id'], 'fleet_driver_id' => $agency ? null : $data['fleet_driver_id'],
                'fleet_agency_contract_id' => $agency ? $data['fleet_agency_contract_id'] : null, 'override_reason' => $data['override_reason'] ?? null,
                'department_id' => $data['department_id'] ?? $r?->department_id] + collect($data)->only(['purpose', 'from_location', 'destination', 'departs_at', 'returns_at', 'passengers', 'cargo', 'cargo_weight'])->all());
            $r?->update(['status' => 'allocated']);
            $trip->load(['unit', 'driver.employee.user', 'contract.supplier']);
            $what = $agency ? $trip->contract->label() : "{$trip->unit->label()} with {$trip->driver->name()}";
            $r?->requester?->notify(new Notice('Transport allocated', "{$r->number}: {$what}, ".$trip->departs_at->format('d M Y H:i').'.', "/self-service/transport/{$r->id}", 'truck-front'));
            $trip->driver?->employee?->user?->notify(new Notice('New trip', "{$trip->number}: {$trip->purpose} to {$trip->destination} with {$trip->unit?->label()}, ".$trip->departs_at->format('d M Y H:i').'.', '/self-service/my-vehicles', 'truck-front'));

            return $trip;
        });
    }

    public function reschedule(Trip $trip, string $departs, string $returns, ?string $override): Trip
    {
        if ($trip->status !== 'scheduled') {
            throw ValidationException::withMessages(['trip' => 'Only a scheduled trip can be moved.']);
        }
        $this->checkTimes($departs, $returns);
        $data = ['departs_at' => $departs, 'returns_at' => $returns, 'fleet_unit_id' => $trip->fleet_unit_id, 'fleet_driver_id' => $trip->fleet_driver_id,
            'fleet_agency_contract_id' => $trip->fleet_agency_contract_id, 'passengers' => $trip->passengers];
        $this->guard($this->check($data, $trip), $override);
        $trip->update(['departs_at' => $departs, 'returns_at' => $returns, 'override_reason' => $override ?? $trip->override_reason]);
        $trip->driver?->employee?->user?->notify(new Notice('Trip moved', "{$trip->number} now leaves ".$trip->departs_at->format('d M Y H:i').'.', '/self-service/my-vehicles', 'truck-front'));

        return $trip->refresh();
    }

    public function cancelTrip(Trip $trip, string $reason): void
    {
        if ($trip->status !== 'scheduled') {
            throw ValidationException::withMessages(['trip' => 'Only a scheduled trip can be cancelled.']);
        }
        DB::transaction(function () use ($trip, $reason) {
            $trip->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            if ($trip->request && $trip->request->status === 'allocated' && ! $trip->request->trips()->whereIn('status', ['scheduled', 'started', 'completed', 'confirmed'])->exists()) {
                $trip->request->update(['status' => 'approved']);
            }
        });
    }

    public function start(Trip $trip, ?float $reading, string $at): Trip
    {
        if ($trip->status !== 'scheduled') {
            throw ValidationException::withMessages(['trip' => 'Only a scheduled trip can start.']);
        }
        if ($trip->unit) {
            if ($reading === null) {
                throw ValidationException::withMessages(['reading' => 'Enter the odometer at departure.']);
            }
            if ($reading < $trip->unit->reading) {
                throw ValidationException::withMessages(['reading' => 'The odometer was already '.number_format($trip->unit->reading, 1).' km.']);
            }
        }
        if (Carbon::parse($at)->gt(now()->addMinutes(15))) {
            throw ValidationException::withMessages(['started_at' => 'A trip is started when it leaves, not ahead of time.']);
        }
        $trip->update(['status' => 'started', 'start_reading' => $reading, 'started_at' => $at]);

        return $trip->refresh();
    }

    public function finish(Trip $trip, array $data, User $by): Trip
    {
        if ($trip->status !== 'started') {
            throw ValidationException::withMessages(['trip' => 'Only a trip on the road can be finished.']);
        }
        $end = Carbon::parse($data['ended_at']);
        if ($end->gt(now()->addMinutes(15))) {
            throw ValidationException::withMessages(['ended_at' => 'The logbook is closed when the vehicle is back, not ahead of time.']);
        }
        if ($end->lt($trip->started_at)) {
            throw ValidationException::withMessages(['ended_at' => 'The return cannot be before the departure.']);
        }

        return DB::transaction(function () use ($trip, $data, $by, $end) {
            if ($trip->unit) {
                $reading = round((float) ($data['reading'] ?? 0), 1);
                if ($reading < $trip->start_reading) {
                    throw ValidationException::withMessages(['reading' => 'The odometer at return cannot be less than at departure ('.number_format($trip->start_reading, 1).').']);
                }
                $trip->update(['end_reading' => $reading]);
                if ($reading > $trip->unit->reading) {
                    $this->units->addReading($trip->unit, $reading, max($end->toDateString(), $trip->unit->reading_date?->toDateString() ?? $end->toDateString()), 'logbook', $trip->number, $by, $trip);
                }
            } else {
                $contract = $trip->contract;
                $qty = $contract->mode === 'trip' ? 1 : round((float) ($data['quantity'] ?? 0), 2);
                if ($qty <= 0) {
                    throw ValidationException::withMessages(['quantity' => $contract->mode === 'km' ? 'Enter the km driven.' : 'Enter the number of days.']);
                }
                $trip->update(['agency_quantity' => $qty, 'agency_amount' => round($qty * $contract->rate, 2)]);
            }
            $trip->update(['status' => 'completed', 'ended_at' => $end, 'logbook_notes' => $data['notes'] ?? null]);

            return $trip->refresh();
        });
    }

    public function confirm(Trip $trip, User $by): Trip
    {
        if ($trip->status !== 'completed') {
            throw ValidationException::withMessages(['trip' => 'Only a finished trip can be confirmed.']);
        }
        if ($trip->driver?->employee?->user_id === $by->id) {
            throw ValidationException::withMessages(['trip' => 'The driver cannot confirm their own logbook.']);
        }
        DB::transaction(function () use ($trip, $by) {
            $trip->update(['status' => 'confirmed', 'confirmed_by' => $by->id, 'confirmed_at' => now()]);
            $r = $trip->request;
            if ($r && ! $r->trips()->whereIn('status', ['scheduled', 'started', 'completed'])->exists()) {
                $r->update(['status' => 'completed']);
            }
        });

        return $trip->refresh();
    }

    private function guard(array $problems, ?string $override): void
    {
        if ($problems['hard']) {
            throw ValidationException::withMessages(['trip' => implode(' ', $problems['hard'])]);
        }
        if ($problems['soft'] && blank($override)) {
            throw ValidationException::withMessages(['override_reason' => implode(' ', $problems['soft']).' Give a reason to go ahead anyway.']);
        }
    }

    private function checkTimes(string $departs, string $returns): void
    {
        if (Carbon::parse($returns)->lte(Carbon::parse($departs))) {
            throw ValidationException::withMessages(['returns_at' => 'The return must be after the departure.']);
        }
    }
}
