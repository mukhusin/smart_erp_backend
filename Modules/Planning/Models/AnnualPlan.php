<?php

namespace Modules\Planning\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;

class AnnualPlan extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'open' => 'Open for planning', 'closed' => 'Closed'];

    protected $fillable = ['company_id', 'strategic_plan_id', 'title', 'year', 'start_date', 'end_date', 'submission_due', 'status', 'created_by'];

    protected $casts = ['year' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'submission_due' => 'date'];

    public function strategicPlan()
    {
        return $this->belongsTo(StrategicPlan::class);
    }

    public function departmentPlans()
    {
        return $this->hasMany(DepartmentPlan::class);
    }

    public function activities()
    {
        return $this->hasMany(TaskActivity::class);
    }
}
