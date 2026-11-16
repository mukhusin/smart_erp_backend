<?php

namespace Modules\Tasks\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\StrategicObjective;

class TaskActivity extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'department_id', 'annual_plan_id', 'strategic_objective_id', 'code', 'year', 'title', 'description', 'output', 'target', 'kpi', 'quarters', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'year' => 'integer'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function annualPlan()
    {
        return $this->belongsTo(AnnualPlan::class);
    }

    public function objective()
    {
        return $this->belongsTo(StrategicObjective::class, 'strategic_objective_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
