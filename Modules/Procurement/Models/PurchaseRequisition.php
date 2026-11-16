<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\Budget;
use Modules\Inventory\Models\Store;
use Modules\Workflow\Models\WorkflowInstance;

class PurchaseRequisition extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = [
        'draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved, to be sourced', 'rejected' => 'Returned', 'cancelled' => 'Cancelled',
        'ordered' => 'Ordered', 'closed' => 'Closed',
    ];

    public const KINDS = ['normal' => 'Normal', 'special' => 'Special / emergency'];

    protected $fillable = ['company_id', 'number', 'department_id', 'budget_id', 'store_id', 'title', 'purpose', 'kind', 'special_reason', 'needed_by', 'delivery_place', 'total',
        'status', 'over_budget', 'requested_by', 'decision_comment', 'submitted_at', 'decided_at'];

    protected $casts = ['needed_by' => 'date', 'total' => 'float', 'over_budget' => 'boolean', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('id');
    }

    public function quotationRequests()
    {
        return $this->hasMany(QuotationRequest::class)->orderBy('id');
    }

    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class)->orderBy('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
