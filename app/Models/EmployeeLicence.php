<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EmployeeLicence extends Model
{
    use Auditable;
    protected $fillable = ['employee_id', 'professional_licence_id', 'licence_no', 'start_date', 'end_date', 'file_path', 'file_name'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function professionalLicence()
    {
        return $this->belongsTo(ProfessionalLicence::class)->withTrashed();
    }
}
