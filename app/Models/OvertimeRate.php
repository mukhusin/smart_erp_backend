<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Overtime pay for a department or section, optionally one job position: per hour or per shift. */
class OvertimeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'department_id', 'section_id', 'job_position_id', 'unit', 'amount', 'is_active', 'updated_by'];

    protected $casts = ['amount' => 'float', 'is_active' => 'boolean'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function section()
    {
        return $this->belongsTo(Department::class, 'section_id');
    }

    public function jobPosition()
    {
        return $this->belongsTo(JobPosition::class);
    }
}
