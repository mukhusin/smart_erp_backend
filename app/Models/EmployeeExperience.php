<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EmployeeExperience extends Model
{
    use Auditable;
    protected $fillable = ['employee_id', 'company_name', 'position', 'start_date', 'end_date', 'salary', 'description'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'salary' => 'decimal:2'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
