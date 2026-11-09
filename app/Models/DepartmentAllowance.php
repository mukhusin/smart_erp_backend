<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DepartmentAllowance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'department_id', 'section_id', 'category_id',
        'job_position_id', 'last_updated_by', 'amount', 'total_amount',
        'rate_payroll_period', 'is_active',
    ];

    protected $casts = ['rate_payroll_period' => 'boolean', 'is_active' => 'boolean'];
    public function department()  { return $this->belongsTo(Department::class); }
    public function section()     { return $this->belongsTo(Department::class, 'section_id'); }
    public function category()    { return $this->belongsTo(AllowanceCategory::class, 'category_id'); }
    public function jobPosition() { return $this->belongsTo(JobPosition::class); }
    public function updatedBy()   { return $this->belongsTo(Employee::class, 'last_updated_by'); }
}
