<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AdmittedEmployee extends Model
{
    use BelongsToCompany;

    protected $table = 'admitted_employees';

    protected $fillable = ['company_id', 'employee_id', 'start_date', 'end_date', 'number_of_days', 'hospital_name', 'admission_reason', 'release_reason', 'remark', 'status', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
