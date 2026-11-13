<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExcuseOfDuty extends Model
{
    use BelongsToCompany;

    protected $table = 'excuse_of_duties';

    protected $fillable = ['company_id', 'employee_id', 'start_date', 'end_date', 'number_of_days', 'hospital_name', 'doctor_name', 'patient_address', 'reason', 'status', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
