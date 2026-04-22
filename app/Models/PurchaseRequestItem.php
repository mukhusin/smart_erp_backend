<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id','item_name','quantity','unit','estimated_cost','notes',
    ];

    protected $casts = ['estimated_cost' => 'decimal:2'];

    public function request() { return $this->belongsTo(PurchaseRequest::class); }
}
