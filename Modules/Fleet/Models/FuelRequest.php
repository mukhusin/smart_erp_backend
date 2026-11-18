<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class FuelRequest extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved, to issue', 'issued' => 'Issued', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $table = 'fleet_fuel_requests';

    protected $fillable = ['company_id', 'number', 'fleet_unit_id', 'fleet_driver_id', 'fleet_trip_id', 'fleet_fuel_store_id', 'department_id', 'litres', 'reading', 'purpose', 'warnings',
        'status', 'issued_litres', 'issue_reading', 'unit_price', 'amount', 'receipt_reference', 'issued_at', 'issued_by', 'fleet_fuel_batch_id', 'decision_comment', 'requested_by'];

    protected $casts = ['litres' => 'float', 'reading' => 'float', 'warnings' => 'array', 'issued_litres' => 'float', 'issue_reading' => 'float', 'unit_price' => 'float',
        'amount' => 'float', 'issued_at' => 'datetime'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'fleet_driver_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'fleet_trip_id');
    }

    public function store()
    {
        return $this->belongsTo(FuelStore::class, 'fleet_fuel_store_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
