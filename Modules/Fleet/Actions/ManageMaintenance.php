<?php

namespace Modules\Fleet\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Fleet\Models\Accident;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Maintenance;
use Modules\Fleet\Models\ServicePlan;
use Modules\Fleet\Support\FleetAccess;
use Modules\Fleet\Support\FleetFiles;
use Modules\Fleet\Workflow\AccidentProcess;
use Modules\Fleet\Workflow\MaintenanceInspectionProcess;
use Modules\Fleet\Workflow\MaintenanceRequestProcess;
use Modules\Procurement\Actions\ManageRequisitions;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageMaintenance
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageUnits $units, private readonly FleetAccess $access) {}

    /**
     * Service plans of a unit with when each is next due.
     *
     * @return Collection<int, array{plan: ServicePlan, next_reading: float|null, next_date: Carbon|null, left_reading: float|null, days_left: int|null, state: string, open: Maintenance|null}>
     */
    public function due(FleetUnit $unit): Collection
    {
        $plans = ServicePlan::where('is_active', true)->where(fn ($q) => $q->where('fleet_unit_id', $unit->id)
            ->orWhere(fn ($q) => $q->whereNull('fleet_unit_id')->whereNotNull('vehicle_type_id')->where('vehicle_type_id', $unit->vehicle_type_id)))->orderBy('name')->get();
        if ($plans->isEmpty()) {
            return collect();
        }
        $first = $unit->readings()->where('status', 'approved')->reorder('date')->orderBy('id')->first();

        return $plans->map(function (ServicePlan $p) use ($unit, $first) {
            $last = Maintenance::where('fleet_unit_id', $unit->id)->where('fleet_service_plan_id', $p->id)->where('status', 'completed')->orderByDesc('completed_on')->orderByDesc('id')->first();
            $baseReading = $last?->completed_reading ?? $first?->reading ?? 0;
            $baseDate = $last?->completed_on ?? $first?->date ?? $unit->created_at;
            $nextReading = $p->every_reading ? round($baseReading + $p->every_reading, 1) : null;
            $nextDate = $p->every_months ? Carbon::parse($baseDate)->addMonthsNoOverflow($p->every_months)->startOfDay() : null;
            $left = $nextReading !== null ? round($nextReading - $unit->reading, 1) : null;
            $days = $nextDate ? (int) today()->diffInDays($nextDate, false) : null;
            $state = ($left !== null && $left <= 0) || ($days !== null && $days <= 0) ? 'overdue'
                : (($left !== null && $left <= $p->warn_reading) || ($days !== null && $days <= $p->warn_days) ? 'due' : 'ok');

            return ['plan' => $p, 'next_reading' => $nextReading, 'next_date' => $nextDate, 'left_reading' => $left, 'days_left' => $days, 'state' => $state,
                'open' => Maintenance::where('fleet_unit_id', $unit->id)->where('fleet_service_plan_id', $p->id)->whereIn('status', Maintenance::OPEN)->first()];
        });
    }

    /** @return array{unit_id: int, unit: string, plan: string, next_reading: float|null, next_date: string|null, left_reading: float|null, days_left: int|null, reading_unit: string, state: string, job: array{id: int, number: string, status_label: string}|null} */
    public function dueRow(FleetUnit $u, array $d): array
    {
        return ['unit_id' => $u->id, 'unit' => $u->label(), 'plan' => $d['plan']->name, 'next_reading' => $d['next_reading'], 'next_date' => $d['next_date']?->toDateString(),
            'left_reading' => $d['left_reading'], 'days_left' => $d['days_left'], 'reading_unit' => $u->unit(), 'state' => $d['state'],
            'job' => $d['open'] ? ['id' => $d['open']->id, 'number' => $d['open']->number, 'status_label' => Maintenance::STATUS_LABELS[$d['open']->status] ?? $d['open']->status] : null];
    }

    /** Opens a service job for every plan that is due and has none open. */
    public function autoServices(): int
    {
        $made = 0;
        foreach (FleetUnit::with(['type', 'activeAssignment.driver.employee.user'])->whereNotIn('status', ['disposed', 'lent'])->get() as $unit) {
            foreach ($this->due($unit)->where('state', '!=', 'ok')->whereNull('open') as $d) {
                $m = Maintenance::create(['number' => Numbering::next(Maintenance::class, 'MNT'), 'fleet_unit_id' => $unit->id, 'kind' => 'preventive', 'fleet_service_plan_id' => $d['plan']->id,
                    'description' => trim($d['plan']->name.($d['plan']->tasks ? "\n".$d['plan']->tasks : '')), 'reading' => $unit->reading, 'status' => 'approved', 'department_id' => $unit->department_id]);
                $when = collect([$d['next_reading'] !== null ? number_format($d['next_reading'], 0).' '.$unit->unit() : null, $d['next_date']?->format('d M Y')])->filter()->join(' or ');
                $notice = new Notice('Service due', "{$unit->label()}: {$d['plan']->name} due at {$when}. Job {$m->number} is open.", "/fleet/maintenance/{$m->id}", 'tools');
                foreach ($this->access->managers()->push($unit->activeAssignment?->driver?->employee?->user)->filter()->unique('id') as $user) {
                    $user->notify($notice);
                }
                $made++;
            }
        }

        return $made;
    }

    public function request(array $data, User $by): Maintenance
    {
        $unit = FleetUnit::findOrFail($data['fleet_unit_id']);
        if ($unit->status === 'disposed') {
            throw ValidationException::withMessages(['fleet_unit_id' => 'This unit is disposed.']);
        }
        if ($open = Maintenance::where('fleet_unit_id', $unit->id)->where('kind', $data['kind'])->whereIn('status', ['pending', 'approved'])->value('number')) {
            throw ValidationException::withMessages(['fleet_unit_id' => "Job {$open} of {$unit->label()} is already open; add to it."]);
        }

        return DB::transaction(function () use ($data, $by, $unit) {
            $m = Maintenance::create(['number' => Numbering::next(Maintenance::class, 'MNT'), 'fleet_unit_id' => $unit->id, 'kind' => $data['kind'], 'fleet_service_plan_id' => $data['fleet_service_plan_id'] ?? null,
                'description' => $data['description'], 'reading' => $data['reading'] ?? $unit->reading, 'status' => 'pending', 'department_id' => $unit->department_id, 'requested_by' => $by->id]);
            if (! $this->workflow->start(MaintenanceRequestProcess::KEY, $m, $by)) {
                $this->approved($m);
            }

            return $m->refresh();
        });
    }

    public function approved(Maintenance $m): void
    {
        $m->update(['status' => 'approved']);
        $notice = new Notice('Repair to inspect', "{$m->number} {$m->unit->label()}: {$m->description}", "/fleet/maintenance/{$m->id}", 'tools');
        foreach ($this->access->managers() as $user) {
            $user->notify($notice);
        }
    }

    public function rejected(Maintenance $m, ?string $comment, bool $withdrawn): void
    {
        $m->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment]);
        if (! $withdrawn) {
            $m->requester?->notify(new Notice('Repair request not approved', "{$m->number}: {$comment}", "/fleet/maintenance/{$m->id}", 'tools'));
        }
    }

    public function withdraw(Maintenance $m, User $by): void
    {
        if ($m->status !== 'pending' || $m->requested_by !== $by->id) {
            throw ValidationException::withMessages(['maintenance' => 'Only your request waiting for approval can be withdrawn.']);
        }
        $instance = $m->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($m, null, true);
    }

    public function cancel(Maintenance $m, string $reason): void
    {
        if (! in_array($m->status, ['approved', 'sourcing'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'Only a job not yet in the workshop can be cancelled.']);
        }
        $m->update(['status' => 'cancelled', 'decision_comment' => $reason]);
    }

    /** Inspection: what is wrong and what it costs; approval buys it through procurement. */
    public function inspect(Maintenance $m, array $data, User $by): Maintenance
    {
        if ($m->status !== 'approved') {
            throw ValidationException::withMessages(['maintenance' => 'Inspect a job that is approved and not yet inspected.']);
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        $estimate = round(array_sum(array_map(fn ($l) => (float) $l['quantity'] * (float) $l['unit_cost'], $lines)), 2);
        if ($estimate > 0 && (empty($data['department_id']) || empty($data['budget_item_id']))) {
            throw ValidationException::withMessages(['budget_item_id' => 'Choose the department and budget line that pay for the repair.']);
        }

        return DB::transaction(function () use ($m, $data, $by, $lines, $estimate) {
            $m->lines()->delete();
            foreach ($lines as $l) {
                $m->lines()->create(['kind' => $l['kind'] ?? 'parts', 'description' => $l['description'], 'quantity' => round((float) $l['quantity'], 2), 'unit_cost' => round((float) $l['unit_cost'], 2),
                    'amount' => round((float) $l['quantity'] * (float) $l['unit_cost'], 2)]);
            }
            $m->update(['supplier_id' => $data['supplier_id'] ?? null, 'workshop' => $data['workshop'] ?? null, 'findings' => $data['findings'], 'estimate' => $estimate,
                'department_id' => $data['department_id'] ?? $m->department_id, 'budget_item_id' => $data['budget_item_id'] ?? null, 'status' => 'inspection', 'inspected_by' => $by->id, 'decision_comment' => null]);
            if (! $this->workflow->start(MaintenanceInspectionProcess::KEY, $m, $by)) {
                $this->inspectionApproved($m);
            }

            return $m->refresh();
        });
    }

    public function inspectionApproved(Maintenance $m): void
    {
        DB::transaction(function () use ($m) {
            $m->load(['lines', 'unit', 'inspector']);
            $by = $m->inspector;
            if ($m->estimate > 0 && $by) {
                $req = app(ManageRequisitions::class);
                $r = $req->save(null, ['department_id' => $m->department_id, 'title' => mb_substr("Repair {$m->unit->label()} ({$m->number})", 0, 200),
                    'purpose' => mb_substr("{$m->number}: ".($m->findings ?: $m->description), 0, 3000), 'kind' => 'normal',
                    'lines' => $m->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => $l->quantity, 'estimated_unit_cost' => $l->unit_cost, 'budget_item_id' => $m->budget_item_id])->all()], $by);
                $req->submit($r, $by);
                $m->update(['purchase_requisition_id' => $r->id]);
            }
            $m->update(['status' => 'sourcing']);
        });
        $m->inspector?->notify(new Notice('Inspection approved', "{$m->number} {$m->unit->label()}".($m->purchase_requisition_id ? ': the requisition is with procurement.' : '.'), "/fleet/maintenance/{$m->id}", 'tools'));
    }

    public function inspectionRejected(Maintenance $m, ?string $comment, bool $withdrawn): void
    {
        $m->update(['status' => 'approved', 'decision_comment' => $withdrawn ? null : $comment]);
        if (! $withdrawn) {
            $m->inspector?->notify(new Notice('Inspection sent back', "{$m->number}: {$comment}", "/fleet/maintenance/{$m->id}", 'tools'));
        }
    }

    /** The unit goes to the workshop: it is not working until the job is done. */
    public function start(Maintenance $m, string $date, User $by): Maintenance
    {
        if (! in_array($m->status, ['sourcing'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'A job goes to the workshop after its inspection is approved.']);
        }
        DB::transaction(function () use ($m, $date, $by) {
            $m->update(['status' => 'in_repair', 'started_on' => $date]);
            if ($m->unit->status === 'operational') {
                $this->units->setStatus($m->unit, 'non_operational', "In the workshop ({$m->number})", $by);
            }
        });

        return $m->refresh();
    }

    public function complete(Maintenance $m, array $data, User $by): Maintenance
    {
        if ($m->status !== 'in_repair') {
            throw ValidationException::withMessages(['maintenance' => 'Only a job in the workshop can be completed.']);
        }
        $unit = $m->unit;
        $reading = isset($data['reading']) ? round((float) $data['reading'], 1) : $unit->reading;
        if ($reading < $unit->reading) {
            throw ValidationException::withMessages(['reading' => 'The reading cannot be below the last one ('.number_format($unit->reading, 1).').']);
        }

        return DB::transaction(function () use ($m, $data, $by, $unit, $reading) {
            $cost = round((float) ($data['actual_cost'] ?? $m->estimate), 2);
            if ($reading > $unit->reading) {
                $this->units->addReading($unit, $reading, max($data['completed_on'], $unit->reading_date?->toDateString() ?? $data['completed_on']), 'maintenance', $m->number, $by, $m);
            }
            if ($cost > 0) {
                $unit->costs()->create(['date' => $data['completed_on'], 'category' => 'maintenance', 'amount' => $cost, 'description' => (Maintenance::KINDS[$m->kind] ?? $m->kind).": {$m->number}",
                    'document_type' => $m->getMorphClass(), 'document_id' => $m->id, 'created_by' => $by->id]);
            }
            $m->update(['status' => 'completed', 'completed_on' => $data['completed_on'], 'completed_reading' => $reading, 'actual_cost' => $cost,
                'invoice_reference' => $data['invoice_reference'] ?? null, 'completion_notes' => $data['notes'] ?? null]);
            $unit->refresh();
            $stillIn = Maintenance::where('fleet_unit_id', $unit->id)->where('status', 'in_repair')->exists();
            if ($unit->status === 'non_operational' && ! $stillIn && ! ($m->accident && $m->accident->outcome === 'write_off')) {
                $this->units->setStatus($unit, 'operational', "Back from the workshop ({$m->number})", $by);
            }
            $m->accident?->update(['status' => 'closed']);

            return $m->refresh();
        });
    }

    /** @param list<UploadedFile> $photos */
    public function report(array $data, array $photos, User $by): Accident
    {
        $unit = FleetUnit::findOrFail($data['fleet_unit_id']);
        if (Carbon::parse($data['happened_at'])->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['happened_at' => 'An accident is reported after it happened.']);
        }
        $driver = ! empty($data['fleet_driver_id']) ? (int) $data['fleet_driver_id'] : ($by->employee ? Driver::where('employee_id', $by->employee->id)->value('id') : null)
            ?? $unit->activeAssignment?->fleet_driver_id;

        return DB::transaction(function () use ($data, $photos, $by, $unit, $driver) {
            $a = Accident::create(['number' => Numbering::next(Accident::class, 'ACC'), 'fleet_unit_id' => $unit->id, 'fleet_driver_id' => $driver, 'fleet_trip_id' => $data['fleet_trip_id'] ?? null,
                'happened_at' => $data['happened_at'], 'location' => $data['location'], 'description' => $data['description'], 'injuries' => (bool) ($data['injuries'] ?? false),
                'third_party' => $data['third_party'] ?? null, 'police_report' => $data['police_report'] ?? null, 'status' => 'pending', 'reported_by' => $by->id]);
            $a->update(['photos' => collect($photos)->map(fn (UploadedFile $f) => ['path' => FleetFiles::store($f, $unit->company_id, "accidents/{$a->id}"), 'name' => mb_substr($f->getClientOriginalName(), 0, 200)])->all()]);
            $notice = new Notice('Accident reported', "{$a->number} {$unit->label()} at {$a->location}".($a->injuries ? ' (people injured)' : '').'.', "/fleet/accidents/{$a->id}", 'exclamation-octagon');
            foreach ($this->access->managers() as $user) {
                $user->notify($notice);
            }
            if (! $this->workflow->start(AccidentProcess::KEY, $a, $by)) {
                $this->accidentReviewed($a);
            }

            return $a->refresh();
        });
    }

    public function accidentReviewed(Accident $a): void
    {
        $a->update(['status' => 'reviewed']);
    }

    public function accidentRejected(Accident $a, ?string $comment, bool $withdrawn): void
    {
        $a->update(['status' => $withdrawn ? 'closed' : 'rejected', 'decision_comment' => $comment]);
        if (! $withdrawn) {
            $a->reporter?->notify(new Notice('Accident report sent back', "{$a->number}: {$comment}", "/fleet/accidents/{$a->id}", 'exclamation-octagon'));
        }
    }

    /** Decide what happens to the unit: repair (opens a repair job), write off (not working; dispose through assets) or nothing. */
    public function assess(Accident $a, array $data, User $by): Accident
    {
        if ($a->status !== 'reviewed' || $a->outcome) {
            throw ValidationException::withMessages(['accident' => 'Assess an accident once, after it is reviewed.']);
        }

        return DB::transaction(function () use ($a, $data, $by) {
            $a->update(['outcome' => $data['outcome'], 'assessment' => $data['assessment'], 'insurance_claim' => $data['insurance_claim'] ?? null,
                'status' => $data['outcome'] === 'repair' ? 'reviewed' : 'closed']);
            $unit = $a->unit;
            if ($data['outcome'] === 'repair') {
                Maintenance::create(['number' => Numbering::next(Maintenance::class, 'MNT'), 'fleet_unit_id' => $unit->id, 'kind' => 'accident', 'fleet_accident_id' => $a->id,
                    'description' => "Accident {$a->number}: {$data['assessment']}", 'reading' => $unit->reading, 'status' => 'approved', 'department_id' => $unit->department_id, 'requested_by' => $by->id]);
            }
            if (in_array($data['outcome'], ['repair', 'write_off'], true) && $unit->status === 'operational') {
                $this->units->setStatus($unit, 'non_operational', $data['outcome'] === 'repair' ? "Accident {$a->number}: waiting for repair" : "Accident {$a->number}: not repairable", $by);
            }

            return $a->refresh();
        });
    }
}
