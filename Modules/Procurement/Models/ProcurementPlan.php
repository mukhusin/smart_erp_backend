<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\Budget;
use Modules\Workflow\Models\WorkflowInstance;

class ProcurementPlan extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned'];

    protected $fillable = ['company_id', 'budget_id', 'title', 'status', 'revision', 'decision_comment', 'approved_at', 'created_by'];

    protected $casts = ['approved_at' => 'datetime', 'revision' => 'integer'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function lines()
    {
        return $this->hasMany(ProcurementPlanLine::class)->orderBy('quarter')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public static function approvedFor(int $budgetId): ?self
    {
        return static::where('budget_id', $budgetId)->where(fn ($q) => $q->where('status', 'approved')->orWhere('revision', '>', 0))->first();
    }
}
