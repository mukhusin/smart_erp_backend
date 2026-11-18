<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class Accident extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for review', 'reviewed' => 'Reviewed, to assess', 'closed' => 'Closed', 'rejected' => 'Sent back'];

    public const OUTCOMES = ['repair' => 'Repairable: sent for repair', 'write_off' => 'Not repairable', 'none' => 'No damage to repair'];

    protected $table = 'fleet_accidents';

    protected $fillable = ['company_id', 'number', 'fleet_unit_id', 'fleet_driver_id', 'fleet_trip_id', 'happened_at', 'location', 'description', 'injuries', 'third_party', 'police_report',
        'photos', 'status', 'outcome', 'assessment', 'insurance_claim', 'decision_comment', 'reported_by'];

    protected $casts = ['happened_at' => 'datetime', 'injuries' => 'boolean', 'photos' => 'array'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'fleet_driver_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function maintenance()
    {
        return $this->hasOne(Maintenance::class, 'fleet_accident_id')->latestOfMany();
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
