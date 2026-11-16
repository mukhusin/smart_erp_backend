<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class SupplierBankAccount extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn', 'disabled' => 'Disabled'];

    protected $fillable = ['company_id', 'supplier_id', 'bank_name', 'branch', 'account_name', 'account_number', 'swift_code', 'currency', 'is_default', 'status', 'requested_by', 'decision_comment', 'decided_at'];

    protected $casts = ['is_default' => 'boolean', 'decided_at' => 'datetime'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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
