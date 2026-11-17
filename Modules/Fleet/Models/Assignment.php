<?php

namespace Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $table = 'fleet_assignments';

    protected $fillable = ['fleet_unit_id', 'fleet_driver_id', 'starts_on', 'ends_on', 'ended_on', 'note', 'created_by'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'ended_on' => 'date'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'fleet_driver_id');
    }

    /** Reachable only through a unit of the current company. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? 'id', $value)->whereHas('unit')->firstOrFail();
    }
}
