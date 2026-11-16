<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['goods_receipt_id', 'purchase_order_line_id', 'delivered_quantity', 'accepted_quantity', 'rejected_quantity', 'rejection_reason', 'batch_number', 'expiry_date', 'serials', 'amount'];

    protected $casts = ['delivered_quantity' => 'float', 'accepted_quantity' => 'float', 'rejected_quantity' => 'float', 'expiry_date' => 'date', 'serials' => 'array', 'amount' => 'float'];

    public function orderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
