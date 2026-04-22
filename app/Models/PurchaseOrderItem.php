<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id','item_name','quantity','unit','unit_price','total_price','notes',
    ];

    protected $casts = [
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function order() { return $this->belongsTo(PurchaseOrder::class); }
}
