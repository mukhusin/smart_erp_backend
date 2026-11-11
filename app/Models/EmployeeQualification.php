<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeQualification extends Model
{
    protected $fillable = [
        'employee_id', 'education_level', 'institution', 'program', 'date_of_start', 'date_of_end', 'end_year',
        'certificate', 'certificate_name',
    ];

    protected $casts = ['date_of_start' => 'date', 'date_of_end' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
