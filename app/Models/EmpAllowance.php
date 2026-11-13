<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Workflow\Models\WorkflowInstance;

/** An allowance assigned to one employee: a fixed amount every month between two dates (HRMS emp_allowances). */
class EmpAllowance extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'department_allowance_id', 'allowance_category_id',
        'hod_approved', 'hr_approved', 'is_hod_approved', 'is_hr_approved',
        'hod_comment', 'hr_comment', 'category', 'duration', 'items', 'amount',
        'amount_per_item', 'start_date', 'end_date', 'is_active', 'description', 'status', 'decision_comment', 'requested_by',
    ];

    protected $casts = ['is_hod_approved' => 'boolean', 'is_hr_approved' => 'boolean', 'is_active' => 'boolean', 'amount' => 'float', 'start_date' => 'date', 'end_date' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function departmentAllowance()
    {
        return $this->belongsTo(DepartmentAllowance::class);
    }

    public function allowanceCategory()
    {
        return $this->belongsTo(AllowanceCategory::class, 'allowance_category_id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
