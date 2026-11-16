<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\Store;
use Modules\Payments\Models\PaymentRequest;
use Modules\Workflow\Models\WorkflowInstance;

class GoodsReceipt extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Inspection and approval', 'accepted' => 'Accepted', 'rejected' => 'Returned', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'number', 'purchase_order_id', 'store_id', 'delivery_note', 'received_on', 'notes', 'status', 'total', 'journal_entry_id', 'created_by',
        'decision_comment', 'decided_at', 'supplier_invoice', 'invoice_date', 'invoice_amount', 'payment_request_id'];

    protected $casts = ['received_on' => 'date', 'invoice_date' => 'date', 'total' => 'float', 'invoice_amount' => 'float', 'decided_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
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
