<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'store_id', 'item_id', 'quantity', 'value', 'last_issue_on'];

    protected $casts = ['quantity' => 'float', 'value' => 'float', 'last_issue_on' => 'date'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function averageCost(): float
    {
        return $this->quantity > 0 ? round($this->value / $this->quantity, 4) : 0.0;
    }
}
