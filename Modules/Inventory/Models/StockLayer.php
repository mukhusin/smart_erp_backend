<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockLayer extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'store_id', 'item_id', 'batch_number', 'expiry_date', 'serial', 'received_on', 'quantity_in', 'quantity_left', 'unit_cost', 'source_type', 'source_id'];

    protected $casts = ['expiry_date' => 'date', 'received_on' => 'date', 'quantity_in' => 'float', 'quantity_left' => 'float', 'unit_cost' => 'float'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
