<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmpAllowance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'department_allowance_id', 'allowance_category_id',
        'hod_approved', 'hr_approved', 'is_hod_approved', 'is_hr_approved',
        'hod_comment', 'hr_comment', 'category', 'duration', 'items', 'amount',
        'amount_per_item', 'start_date', 'end_date', 'is_active', 'description', 'status',
    ];

    protected $casts = ['is_hod_approved' => 'boolean', 'is_hr_approved' => 'boolean', 'is_active' => 'boolean'];

    public function employee()            { return $this->belongsTo(Employee::class); }
    public function departmentAllowance() { return $this->belongsTo(DepartmentAllowance::class); }
    public function category()            { return $this->belongsTo(AllowanceCategory::class, 'allowance_category_id'); }
    public function hodApprover()         { return $this->belongsTo(Employee::class, 'hod_approved'); }
    public function hrApprover()          { return $this->belongsTo(Employee::class, 'hr_approved'); }
    public function company()             { return $this->belongsTo(Company::class); }
}
