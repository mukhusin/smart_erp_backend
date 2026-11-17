<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class ContractVariation extends Model
{

    use \App\Models\Concerns\BelongsToCompany;

    public const KINDS = ['addendum' => 'Addendum (scope or price)', 'extension' => 'Extension of time'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(\Modules\Workflow\Models\WorkflowInstance::class, 'subject')->latestOfMany();
    }

    protected $fillable = ['company_id', 'number', 'contract_id', 'kind', 'amount', 'budget_item_id', 'new_end_date', 'reason', 'status', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['amount' => 'float', 'new_end_date' => 'date', 'decided_at' => 'datetime'];
}
