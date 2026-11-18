<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\Supplier;

/** Own tank (litres in stock) or a filling station account (fuel on credit, paid in batches). */
class FuelStore extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['own' => 'Own tank', 'station' => 'Filling station account'];

    protected $table = 'fleet_fuel_stores';

    protected $fillable = ['company_id', 'name', 'kind', 'fuel_type', 'capacity', 'reorder_level', 'supplier_id', 'account_reference', 'price', 'is_active'];

    protected $casts = ['capacity' => 'float', 'reorder_level' => 'float', 'price' => 'float', 'is_active' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements()
    {
        return $this->hasMany(FuelMovement::class, 'fleet_fuel_store_id')->orderByDesc('date')->orderByDesc('id');
    }

    public function requests()
    {
        return $this->hasMany(FuelRequest::class, 'fleet_fuel_store_id');
    }

    public function isOwn(): bool
    {
        return $this->kind === 'own';
    }

    public function balance(): float
    {
        return round((float) $this->movements()->sum('litres'), 2);
    }

    /** Litres approved but not yet issued. */
    public function committed(): float
    {
        return round((float) $this->requests()->where('status', 'approved')->sum('litres'), 2);
    }

    /** Moving average price of the litres in the tank. */
    public function averagePrice(): float
    {
        $litres = (float) $this->movements()->sum('litres');
        $amount = (float) $this->movements()->sum('amount');

        return $litres > 0 ? round($amount / $litres, 2) : (float) ($this->price ?? 0);
    }
}
