<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'company_id','purchase_request_id','supplier_id','po_number','date',
        'status','total_amount','notes',
    ];

    protected $casts = [
        'date'         => 'date',
        'total_amount' => 'decimal:2',
    ];

    const STATUSES = ['draft','sent','confirmed','received','cancelled'];

    public function company()         { return $this->belongsTo(Company::class); }
    public function purchaseRequest() { return $this->belongsTo(PurchaseRequest::class); }
    public function supplier()        { return $this->belongsTo(Supplier::class); }
    public function items()           { return $this->hasMany(PurchaseOrderItem::class); }
}
