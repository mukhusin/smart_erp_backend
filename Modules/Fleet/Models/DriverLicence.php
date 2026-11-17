<?php

namespace Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLicence extends Model
{
    protected $table = 'fleet_driver_licences';

    protected $fillable = ['fleet_driver_id', 'number', 'classes', 'issued_on', 'expires_on', 'is_current', 'document_path', 'document_name', 'created_by'];

    protected $casts = ['classes' => 'array', 'issued_on' => 'date', 'expires_on' => 'date', 'is_current' => 'boolean'];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'fleet_driver_id');
    }

    public function valid(): bool
    {
        return $this->is_current && $this->expires_on->gte(today());
    }

    /** Reachable only through a driver of the current company. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? 'id', $value)->whereHas('driver')->firstOrFail();
    }
}
