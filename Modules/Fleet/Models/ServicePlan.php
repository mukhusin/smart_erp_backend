<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Preventive service every so many km / hours or months, whichever comes first; for a vehicle type or one unit. */
class ServicePlan extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'fleet_service_plans';

    protected $fillable = ['company_id', 'name', 'vehicle_type_id', 'fleet_unit_id', 'every_reading', 'every_months', 'warn_reading', 'warn_days', 'tasks', 'is_active'];

    protected $casts = ['every_reading' => 'float', 'every_months' => 'integer', 'warn_reading' => 'float', 'warn_days' => 'integer', 'is_active' => 'boolean'];

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }
}
