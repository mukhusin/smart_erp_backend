<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** The staff member's registration number in the hospital's patient system (e.g. AfyaBox). */
class EmployeePatientId extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'patient_id'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
