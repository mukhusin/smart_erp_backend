<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Store;
use Modules\Workflow\Models\WorkflowInstance;

class PurchaseOrder extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = [
        'draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved, waiting for delivery', 'rejected' => 'Returned', 'cancelled' => 'Cancelled',
        'partial' => 'Partly delivered', 'received' => 'Delivered', 'closed' => 'Closed',
    ];

    public const METHODS = ['quotation' => 'Request for quotations', 'direct' => 'Direct purchase', 'single' => 'Single source', 'framework' => 'Framework call-off'];

    protected $fillable = ['company_id', 'number', 'supplier_id', 'purchase_requisition_id', 'quotation_id', 'store_id', 'method', 'method_reason', 'order_date', 'delivery_date',
        'delivery_place', 'payment_terms_days', 'total', 'status', 'acknowledged_at', 'notes', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['order_date' => 'date', 'delivery_date' => 'date', 'total' => 'float', 'acknowledged_at' => 'datetime', 'decided_at' => 'datetime', 'payment_terms_days' => 'integer'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function receivable(): bool
    {
        return in_array($this->status, ['approved', 'partial'], true);
    }
}
