<?php

namespace Modules\Planning\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;

class DepartmentPlan extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned'];

    protected $fillable = ['company_id', 'annual_plan_id', 'department_id', 'status', 'submitted_by', 'submitted_at', 'decision_comment', 'decided_at'];

    protected $casts = ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function annualPlan()
    {
        return $this->belongsTo(AnnualPlan::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function activities()
    {
        return $this->hasMany(TaskActivity::class, 'annual_plan_id', 'annual_plan_id')->where('department_id', $this->department_id);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
