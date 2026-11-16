<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class BudgetRelease extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'opened' => 'Open', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'budget_id', 'period', 'status', 'requested_by', 'decision_comment', 'opened_at'];

    protected $casts = ['period' => 'integer', 'opened_at' => 'datetime'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
