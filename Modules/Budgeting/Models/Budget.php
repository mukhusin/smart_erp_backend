<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\FiscalYear;
use Modules\Planning\Models\AnnualPlan;
use Modules\Workflow\Models\WorkflowInstance;

class Budget extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'open' => 'Departments preparing', 'pending' => 'Waiting for final approval', 'approved' => 'Approved', 'rejected' => 'Returned', 'closed' => 'Closed'];

    protected $fillable = ['company_id', 'fiscal_year_id', 'annual_plan_id', 'title', 'phasing', 'status', 'submission_due', 'decision_comment', 'approved_at', 'created_by'];

    protected $casts = ['submission_due' => 'date', 'approved_at' => 'datetime'];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function annualPlan()
    {
        return $this->belongsTo(AnnualPlan::class);
    }

    public function departmentBudgets()
    {
        return $this->hasMany(DepartmentBudget::class);
    }

    public function items()
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function releases()
    {
        return $this->hasMany(BudgetRelease::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function periods(): int
    {
        return ['quarter' => 4, 'month' => 12, 'year' => 1][$this->phasing] ?? 4;
    }

    public function periodLabels(): array
    {
        return match ($this->phasing) {
            'month' => $this->fiscalYear?->periods->pluck('name')->all() ?: array_map(fn ($i) => 'Month '.$i, range(1, 12)),
            'year' => ['Year'],
            default => ['Q1', 'Q2', 'Q3', 'Q4'],
        };
    }

    public function editable(): bool
    {
        return in_array($this->status, ['open', 'rejected'], true);
    }
}
