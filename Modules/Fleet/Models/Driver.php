<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\EmployeeLicence;
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

    /** Driving licences on the employee's HR profile (approved), newest expiry first. */
    public function licences()
    {
        return $this->hasMany(EmployeeLicence::class, 'employee_id', 'employee_id')->whereHas('professionalLicence', fn ($q) => $q->where('is_driving', true))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'approved'))->orderByDesc('end_date')->orderByDesc('id');
    }

    public function currentLicence(): ?EmployeeLicence
    {
        return $this->licences->first();
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
