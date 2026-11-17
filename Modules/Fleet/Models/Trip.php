<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['scheduled' => 'Scheduled', 'started' => 'On the road', 'completed' => 'Back, logbook to confirm', 'confirmed' => 'Logbook confirmed', 'cancelled' => 'Cancelled'];

    public const BUSY = ['scheduled', 'started'];

    protected $table = 'fleet_trips';

    protected $fillable = ['company_id', 'number', 'transport_request_id', 'fleet_unit_id', 'fleet_driver_id', 'fleet_agency_contract_id', 'department_id', 'purpose', 'from_location',
        'destination', 'departs_at', 'returns_at', 'passengers', 'cargo', 'cargo_weight', 'status', 'override_reason', 'start_reading', 'started_at', 'end_reading', 'ended_at',
        'logbook_notes', 'confirmed_by', 'confirmed_at', 'agency_quantity', 'agency_amount', 'fleet_agency_batch_id', 'cancel_reason', 'created_by'];

    protected $casts = ['departs_at' => 'datetime', 'returns_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'confirmed_at' => 'datetime',
        'start_reading' => 'float', 'end_reading' => 'float', 'cargo_weight' => 'float', 'passengers' => 'integer', 'agency_quantity' => 'float', 'agency_amount' => 'float'];

    public function request()
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id');
    }

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'fleet_driver_id');
    }

    public function contract()
    {
        return $this->belongsTo(AgencyContract::class, 'fleet_agency_contract_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function distance(): ?float
    {
        return $this->start_reading !== null && $this->end_reading !== null ? round($this->end_reading - $this->start_reading, 1) : null;
    }
}
