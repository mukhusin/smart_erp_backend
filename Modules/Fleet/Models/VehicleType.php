<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'fleet_vehicle_types';

    protected $fillable = ['company_id', 'name', 'kind', 'licence_classes', 'tank_capacity', 'is_active'];

    protected $casts = ['licence_classes' => 'array', 'tank_capacity' => 'float', 'is_active' => 'boolean'];
}
