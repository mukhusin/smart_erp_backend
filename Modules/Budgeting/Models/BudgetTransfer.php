<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class BudgetTransfer extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['transfer' => 'Transfer', 'supplementary' => 'Supplementary budget'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'budget_id', 'kind', 'from_item_id', 'to_item_id', 'amount', 'reason', 'status', 'requested_by', 'decision_comment', 'decided_at'];

    protected $casts = ['amount' => 'float', 'decided_at' => 'datetime'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function fromItem()
    {
        return $this->belongsTo(BudgetItem::class, 'from_item_id');
    }

    public function toItem()
    {
        return $this->belongsTo(BudgetItem::class, 'to_item_id');
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
