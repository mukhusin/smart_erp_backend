<?php

namespace Modules\Fleet\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Models\Asset;

class FleetUnit extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['vehicle' => 'Vehicle', 'generator' => 'Generator'];

    public const STATUSES = ['operational' => 'Operational', 'non_operational' => 'Not working', 'off_limit' => 'Off limits', 'lent' => 'Lent out', 'disposed' => 'Disposed'];

    public const OWNERSHIP = ['owned' => 'Owned', 'donated' => 'Donated', 'borrowed' => 'Borrowed', 'hired' => 'Hired', 'leased' => 'Leased'];

    public const FUELS = ['petrol' => 'Petrol', 'diesel' => 'Diesel', 'electric' => 'Electric', 'hybrid' => 'Hybrid', 'gas' => 'Gas'];

    protected $fillable = ['company_id', 'kind', 'vehicle_type_id', 'registration', 'name', 'make', 'model', 'year', 'chassis_number', 'engine_number', 'colour', 'seats', 'fuel_type',
        'tank_capacity', 'ownership', 'owner_name', 'asset_id', 'company_branch_id', 'department_id', 'location', 'status', 'status_note', 'reading', 'reading_date', 'notes', 'created_by'];

    protected $casts = ['year' => 'integer', 'seats' => 'integer', 'tank_capacity' => 'float', 'reading' => 'float', 'reading_date' => 'date'];

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function readings()
    {
        return $this->hasMany(Reading::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class)->orderByDesc('starts_on')->orderByDesc('id');
    }

    public function activeAssignment()
    {
        return $this->hasOne(Assignment::class)->whereNull('ended_on')->latestOfMany('starts_on');
    }

    public function compliance()
    {
        return $this->hasMany(Compliance::class)->orderByDesc('expires_on');
    }

    public function costs()
    {
        return $this->hasMany(Cost::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function statusChanges()
    {
        return $this->hasMany(StatusChange::class)->orderByDesc('id');
    }

    public function unit(): string
    {
        return $this->kind === 'generator' ? 'hours' : 'km';
    }

    public function label(): string
    {
        return trim(($this->registration ? $this->registration.' ' : '').$this->name);
    }
}
