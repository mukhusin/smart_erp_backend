<?php

namespace Modules\Fleet\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Models\Asset;
use Modules\Core\Notifications\Notice;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Cost;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Reading;
use Modules\Fleet\Support\FleetFiles;
use Modules\Fleet\Workflow\ReadingCorrectionProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageUnits
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function save(?FleetUnit $unit, array $data, User $by): FleetUnit
    {
        $kind = $data['kind'] ?? $unit?->kind ?? 'vehicle';
        if ($kind === 'vehicle' && empty($data['registration'])) {
            throw ValidationException::withMessages(['registration' => 'Enter the registration number of the vehicle.']);
        }
        $registration = ! empty($data['registration']) ? strtoupper(preg_replace('/\s+/', ' ', trim($data['registration']))) : null;
        if ($registration && FleetUnit::where('registration', $registration)->when($unit, fn ($q) => $q->where('id', '!=', $unit->id))->exists()) {
            throw ValidationException::withMessages(['registration' => "{$registration} is already registered."]);
        }
        $ownership = $data['ownership'] ?? 'owned';
        $assetId = in_array($ownership, ['owned', 'donated'], true) ? ($data['asset_id'] ?? null) : null;
        if ($assetId) {
            $asset = Asset::find($assetId);
            if (! $asset || $asset->status === 'disposed') {
                throw ValidationException::withMessages(['asset_id' => 'Choose an asset still in the register.']);
            }
            if (FleetUnit::where('asset_id', $assetId)->when($unit, fn ($q) => $q->where('id', '!=', $unit->id))->exists()) {
                throw ValidationException::withMessages(['asset_id' => "Asset {$asset->number} is already linked to another fleet unit."]);
            }
        }
        if (in_array($ownership, ['borrowed', 'hired', 'leased'], true) && empty($data['owner_name'])) {
            throw ValidationException::withMessages(['owner_name' => 'Enter who the vehicle belongs to.']);
        }

        return DB::transaction(function () use ($unit, $data, $by, $kind, $registration, $ownership, $assetId) {
            $new = ! $unit;
            $unit ??= new FleetUnit(['status' => 'operational', 'created_by' => $by->id, 'kind' => $kind]);
            $unit->fill(collect($data)->only(['vehicle_type_id', 'name', 'make', 'model', 'year', 'chassis_number', 'engine_number', 'colour', 'seats', 'fuel_type', 'tank_capacity',
                'owner_name', 'company_branch_id', 'department_id', 'location', 'notes'])->all());
            $unit->fill(['registration' => $registration, 'ownership' => $ownership, 'asset_id' => $assetId, 'owner_name' => in_array($ownership, ['owned', 'donated'], true) ? null : ($data['owner_name'] ?? null)]);
            if ($new) {
                $unit->fill(['reading' => round((float) ($data['reading'] ?? 0), 1), 'reading_date' => $data['reading_date'] ?? today()->toDateString()]);
            }
            $unit->save();
            if ($new) {
                $unit->readings()->create(['reading' => $unit->reading, 'date' => $unit->reading_date, 'source' => 'registration', 'status' => 'approved', 'created_by' => $by->id]);
                $unit->statusChanges()->create(['to' => 'operational', 'note' => 'Registered', 'changed_by' => $by->id]);
            }

            return $unit->refresh();
        });
    }

    public function setStatus(FleetUnit $unit, string $status, ?string $note, User $by): FleetUnit
    {
        if ($unit->status === 'disposed') {
            throw ValidationException::withMessages(['status' => 'A disposed unit cannot change status.']);
        }
        if ($status === 'disposed' && $unit->asset_id && $unit->asset?->status !== 'disposed') {
            throw ValidationException::withMessages(['status' => "Dispose of it through the asset register (asset {$unit->asset?->number}); the fleet record closes when the disposal is approved."]);
        }
        if ($status === $unit->status) {
            return $unit;
        }
        DB::transaction(function () use ($unit, $status, $note, $by) {
            $unit->statusChanges()->create(['from' => $unit->status, 'to' => $status, 'note' => $note, 'changed_by' => $by->id]);
            $unit->update(['status' => $status, 'status_note' => $note]);
            if ($status === 'disposed') {
                $unit->assignments()->whereNull('ended_on')->update(['ended_on' => today()->toDateString()]);
            }
        });

        return $unit->refresh();
    }

    /** A reading only moves forward; a lower one is a correction that needs approval. */
    public function addReading(FleetUnit $unit, float $value, string $date, string $source, ?string $note, User $by, $document = null): Reading
    {
        $value = round($value, 1);
        if ($value < $unit->reading) {
            throw ValidationException::withMessages(['reading' => 'The '.($unit->kind === 'generator' ? 'hours' : 'odometer').' cannot go back from '.number_format($unit->reading, 1).'. Ask for a correction instead.']);
        }
        if ($unit->reading_date && Carbon::parse($date)->lt($unit->reading_date)) {
            throw ValidationException::withMessages(['date' => 'A reading cannot be dated before the last one ('.$unit->reading_date->format('d M Y').').']);
        }
        if (Carbon::parse($date)->gt(today())) {
            throw ValidationException::withMessages(['date' => 'A reading cannot be in the future.']);
        }

        return DB::transaction(function () use ($unit, $value, $date, $source, $note, $by, $document) {
            $r = $unit->readings()->create(['reading' => $value, 'previous' => $unit->reading, 'date' => $date, 'source' => $source, 'status' => 'approved', 'note' => $note, 'created_by' => $by->id,
                'document_type' => $document?->getMorphClass(), 'document_id' => $document?->getKey()]);
            $unit->update(['reading' => $value, 'reading_date' => $date]);

            return $r;
        });
    }

    public function requestCorrection(FleetUnit $unit, float $value, string $reason, User $by): Reading
    {
        if ($unit->readings()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['reading' => 'A correction of this unit is already waiting for approval.']);
        }

        return DB::transaction(function () use ($unit, $value, $reason, $by) {
            $r = $unit->readings()->create(['reading' => round($value, 1), 'previous' => $unit->reading, 'date' => today()->toDateString(), 'source' => 'correction', 'status' => 'pending',
                'note' => $reason, 'created_by' => $by->id]);
            if (! $this->workflow->start(ReadingCorrectionProcess::KEY, $r, $by)) {
                $this->correctionApproved($r);
            }

            return $r->refresh();
        });
    }

    public function correctionApproved(Reading $r): void
    {
        DB::transaction(function () use ($r) {
            $r->update(['status' => 'approved']);
            $r->unit->update(['reading' => $r->reading, 'reading_date' => $r->date]);
        });
        $r->creator?->notify(new Notice('Reading corrected', "{$r->unit->label()}: reading set to ".number_format($r->reading, 1).'.', "/fleet/units/{$r->fleet_unit_id}", 'speedometer2'));
    }

    public function correctionRejected(Reading $r, ?string $comment, bool $withdrawn): void
    {
        $r->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment]);
        if (! $withdrawn) {
            $r->creator?->notify(new Notice('Reading correction not approved', "{$r->unit->label()}: {$comment}", "/fleet/units/{$r->fleet_unit_id}", 'speedometer2'));
        }
    }

    public function withdrawCorrection(Reading $r, User $by): void
    {
        if ($r->status !== 'pending' || $r->created_by !== $by->id) {
            throw ValidationException::withMessages(['reading' => 'Only your correction waiting for approval can be withdrawn.']);
        }
        $instance = $r->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->correctionRejected($r, null, true);
    }

    /** A new insurance, licence or certificate replaces the current one of the same type; its cost goes to the unit's running costs. */
    public function addCompliance(FleetUnit $unit, array $data, ?UploadedFile $file, User $by): Compliance
    {
        if (! empty($data['starts_on']) && Carbon::parse($data['starts_on'])->gt(Carbon::parse($data['expires_on']))) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date must be after the start date.']);
        }

        return DB::transaction(function () use ($unit, $data, $file, $by) {
            $unit->compliance()->where('type', $data['type'])->where('is_current', true)->update(['is_current' => false]);
            $c = $unit->compliance()->create(collect($data)->only(['type', 'reference', 'supplier_id', 'provider', 'cover', 'starts_on', 'expires_on', 'amount', 'notes'])->all()
                + ['is_current' => true, 'created_by' => $by->id]);
            if ($file) {
                $c->update(['document_path' => FleetFiles::store($file, $unit->company_id, "units/{$unit->id}"), 'document_name' => mb_substr($file->getClientOriginalName(), 0, 200)]);
            }
            if ((float) ($data['amount'] ?? 0) > 0) {
                $unit->costs()->create(['date' => $data['starts_on'] ?? today()->toDateString(), 'category' => $data['type'] === 'insurance' ? 'insurance' : 'licence', 'amount' => round((float) $data['amount'], 2),
                    'description' => Compliance::TYPES[$data['type']].(! empty($data['reference']) ? " {$data['reference']}" : ''), 'document_type' => $c->getMorphClass(), 'document_id' => $c->id, 'created_by' => $by->id]);
            }

            return $c;
        });
    }

    public function addCost(FleetUnit $unit, array $data, User $by): Cost
    {
        return $unit->costs()->create(['date' => $data['date'], 'category' => $data['category'], 'amount' => round((float) $data['amount'], 2), 'description' => $data['description'] ?? null, 'created_by' => $by->id]);
    }

    public function removeCost(Cost $cost): void
    {
        if ($cost->document_type) {
            throw ValidationException::withMessages(['cost' => 'This cost comes from a document; change the document instead.']);
        }
        $cost->delete();
    }
}
