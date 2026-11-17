<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'left' => 'No longer drives'];

    protected $table = 'fleet_drivers';

    protected $fillable = ['company_id', 'employee_id', 'status', 'notes', 'created_by'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function licences()
    {
        return $this->hasMany(DriverLicence::class, 'fleet_driver_id')->orderByDesc('id');
    }

    public function licence()
    {
        return $this->hasOne(DriverLicence::class, 'fleet_driver_id')->where('is_current', true)->latestOfMany();
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'fleet_driver_id')->orderByDesc('starts_on');
    }

    public function name(): string
    {
        return (string) $this->employee?->full_name;
    }
}
