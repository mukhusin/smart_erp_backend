<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** Changes to a published duty roster asked for by a head of section; applied once the head of department approves. */
class RosterChangeRequest extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'applied' => 'Approved and applied', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'roster_id', 'items', 'reason', 'status', 'requested_by', 'decision_comment', 'decided_at'];

    protected $casts = ['items' => 'array', 'decided_at' => 'datetime'];

    public function roster()
    {
        return $this->belongsTo(Roster::class);
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
