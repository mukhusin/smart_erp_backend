<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\Supplier;

/** Vehicles hired in from a supplier for a period; they are fleet units while the hire runs. */
class Hire extends Model
{
    use Auditable, BelongsToCompany;

    public const MODES = ['day' => 'Per day per vehicle', 'month' => 'Per month per vehicle'];

    public const STATUS_LABELS = ['active' => 'Running', 'ended' => 'Ended', 'cancelled' => 'Cancelled'];

    protected $table = 'fleet_hires';

    protected $fillable = ['company_id', 'number', 'supplier_id', 'reference', 'vehicle_type_id', 'starts_on', 'ends_on', 'rate_mode', 'rate', 'currency', 'notes', 'status', 'ended_on',
        'end_note', 'created_by'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'ended_on' => 'date', 'rate' => 'float'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function units()
    {
        return $this->hasMany(FleetUnit::class, 'fleet_hire_id');
    }

    public function payments()
    {
        return $this->hasMany(HirePayment::class, 'fleet_hire_id')->orderBy('from_date');
    }

    public function lastDay()
    {
        return $this->ended_on ?? $this->ends_on;
    }
}
