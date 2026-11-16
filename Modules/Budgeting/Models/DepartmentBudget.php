<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class DepartmentBudget extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned'];

    protected $fillable = ['company_id', 'budget_id', 'department_id', 'status', 'ceiling', 'submitted_by', 'submitted_at', 'decision_comment', 'decided_at'];

    protected $casts = ['submitted_at' => 'datetime', 'decided_at' => 'datetime', 'ceiling' => 'float'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items()
    {
        return $this->hasMany(BudgetItem::class, 'budget_id', 'budget_id')->where('department_id', $this->department_id);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
