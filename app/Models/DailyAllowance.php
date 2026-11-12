<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Workflow\Models\WorkflowInstance;

/** An allowance earned on a day (or month): generated from the working calendar, or claimed and approved. */
class DailyAllowance extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id', 'employee_id', 'department_allowance_id', 'allowance_category_id', 'payroll_sheet_detail_id',
        'hod_approved', 'hr_approved', 'is_hod_approved', 'is_hr_approved', 'hod_comment', 'hr_comment',
        'category', 'duration', 'items', 'amount', 'amount_per_item', 'start_date', 'end_date',
        'is_active', 'description', 'status', 'source', 'requested_by', 'decided_at', 'decision_comment',
    ];

    protected $casts = [
        'is_hod_approved' => 'boolean', 'is_hr_approved' => 'boolean', 'is_active' => 'boolean', 'start_date' => 'date', 'end_date' => 'date',
        'items' => 'float', 'amount' => 'float', 'amount_per_item' => 'float', 'decided_at' => 'datetime',
    ];

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
        return $this->belongsTo(AllowanceCategory::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function hodApprover()
    {
        return $this->belongsTo(Employee::class, 'hod_approved');
    }

    public function hrApprover()
    {
        return $this->belongsTo(Employee::class, 'hr_approved');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
