<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use Auditable, BelongsToCompany;

    public const USES = ['internal' => 'Used internally', 'sale' => 'For sale', 'both' => 'Used and sold'];

    protected $fillable = ['company_id', 'code', 'name', 'description', 'item_category_id', 'type', 'use', 'unit_id', 'track_batch', 'track_expiry', 'track_serial',
        'min_level', 'max_level', 'reorder_level', 'last_cost', 'selling_price', 'barcode', 'is_active', 'created_by'];

    protected $casts = ['track_batch' => 'boolean', 'track_expiry' => 'boolean', 'track_serial' => 'boolean', 'is_active' => 'boolean',
        'min_level' => 'float', 'max_level' => 'float', 'reorder_level' => 'float', 'last_cost' => 'float', 'selling_price' => 'float'];

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function units()
    {
        return $this->hasMany(ItemUnit::class)->orderBy('factor');
    }

    public function levels()
    {
        return $this->hasMany(ItemStoreLevel::class);
    }

    /** Quantity in the base unit for a quantity in the given unit. */
    public function toBase(float $quantity, ?int $unitId): float
    {
        if (! $unitId || $unitId === $this->unit_id) {
            return $quantity;
        }
        $factor = $this->units->firstWhere('unit_id', $unitId)?->factor;
        abort_unless($factor, 422, "{$this->name} has no conversion for that unit.");

        return round($quantity * $factor, 4);
    }
}
