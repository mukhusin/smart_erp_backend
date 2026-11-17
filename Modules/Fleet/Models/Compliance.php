<?php

namespace Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\Supplier;

class Compliance extends Model
{
    public const TYPES = ['insurance' => 'Insurance', 'road_licence' => 'Road licence', 'inspection' => 'Inspection certificate', 'permit' => 'Permit', 'other' => 'Other'];

    protected $table = 'fleet_compliance';

    protected $fillable = ['fleet_unit_id', 'type', 'reference', 'supplier_id', 'provider', 'cover', 'starts_on', 'expires_on', 'amount', 'is_current', 'document_path', 'document_name', 'notes', 'created_by'];

    protected $casts = ['starts_on' => 'date', 'expires_on' => 'date', 'amount' => 'float', 'is_current' => 'boolean'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Reachable only through a unit of the current company. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? 'id', $value)->whereHas('unit')->firstOrFail();
    }
}
