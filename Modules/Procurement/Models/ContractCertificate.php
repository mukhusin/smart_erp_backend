<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class ContractCertificate extends Model
{

    use \App\Models\Concerns\BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved, sent for payment', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

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

    protected $fillable = ['company_id', 'number', 'contract_id', 'contract_milestone_id', 'date', 'period', 'percent_complete', 'gross', 'retention', 'damages', 'days_late', 'advance_recovery', 'net', 'description', 'path', 'file_name', 'status', 'journal_entry_id', 'payment_request_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'gross' => 'float', 'retention' => 'float', 'damages' => 'float', 'advance_recovery' => 'float', 'net' => 'float', 'decided_at' => 'datetime'];
}
