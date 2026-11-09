<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','inventory_category_id','code','name','description',
        'unit','unit_cost','quantity_on_hand','minimum_stock','is_active',
    ];

    protected $casts = [
        'unit_cost'       => 'decimal:2',
        'quantity_on_hand'=> 'decimal:2',
        'minimum_stock'   => 'decimal:2',
        'is_active'       => 'boolean',
    ];
    public function category() { return $this->belongsTo(InventoryCategory::class, 'inventory_category_id'); }
    public function movements(){ return $this->hasMany(StockMovement::class); }

    public function isLowStock(): bool
    {
        return $this->quantity_on_hand <= $this->minimum_stock;
    }
}
