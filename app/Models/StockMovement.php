<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id','type','quantity','reference','notes','date','created_by',
    ];

    protected $casts = [
        'date'     => 'date',
        'quantity' => 'decimal:2',
    ];

    const TYPES = ['in','out','adjustment'];

    public function item()    { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
