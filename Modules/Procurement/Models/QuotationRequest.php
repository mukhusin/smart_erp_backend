<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class QuotationRequest extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['open' => 'Collecting quotations', 'pending' => 'Award waiting for approval', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'number', 'purchase_requisition_id', 'title', 'closing_date', 'instructions', 'status', 'award_comment', 'created_by', 'decided_at'];

    protected $casts = ['closing_date' => 'date', 'decided_at' => 'datetime'];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class)->orderBy('total');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
