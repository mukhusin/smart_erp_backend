<?php

namespace Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class Reading extends Model
{
    public const SOURCES = ['registration' => 'Registration', 'manual' => 'Entered', 'logbook' => 'Logbook', 'fuel' => 'Fuel issue', 'maintenance' => 'Maintenance', 'tracking' => 'Tracking', 'correction' => 'Correction'];

    protected $table = 'fleet_readings';

    protected $fillable = ['fleet_unit_id', 'reading', 'previous', 'date', 'source', 'document_type', 'document_id', 'status', 'note', 'created_by', 'decision_comment'];

    protected $casts = ['reading' => 'float', 'previous' => 'float', 'date' => 'date'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    /** Reachable only through a unit of the current company. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? 'id', $value)->whereHas('unit')->firstOrFail();
    }
}
