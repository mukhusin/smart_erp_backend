<?php

namespace Modules\Appraisals\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class Appraisal extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['open' => 'Open', 'review' => 'Under review', 'returned' => 'Returned', 'acknowledge' => 'Waiting for employee', 'done' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id', 'appraisal_cycle_id', 'employee_id', 'department_id', 'status', 'mid_year_comment', 'mid_year_by', 'mid_year_at', 'self_comment', 'submitted_at',
        'decision_comment', 'final_score', 'rating', 'employee_comment', 'employee_agrees', 'acknowledged_at',
    ];

    protected $casts = ['mid_year_at' => 'datetime', 'submitted_at' => 'datetime', 'acknowledged_at' => 'datetime', 'final_score' => 'float', 'employee_agrees' => 'boolean'];

    public function cycle()
    {
        return $this->belongsTo(AppraisalCycle::class, 'appraisal_cycle_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function scores()
    {
        return $this->hasMany(AppraisalScore::class);
    }

    public function midYearBy()
    {
        return $this->belongsTo(User::class, 'mid_year_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
