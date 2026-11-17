<?php

namespace Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Cost extends Model
{
    public const CATEGORIES = ['fuel' => 'Fuel', 'maintenance' => 'Maintenance and repair', 'insurance' => 'Insurance', 'licence' => 'Licences and permits', 'hire' => 'Hire', 'tyres' => 'Tyres', 'other' => 'Other'];

    protected $table = 'fleet_costs';

    protected $fillable = ['fleet_unit_id', 'date', 'category', 'amount', 'description', 'document_type', 'document_id', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'float'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    /** Reachable only through a unit of the current company. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? 'id', $value)->whereHas('unit')->firstOrFail();
    }
}
