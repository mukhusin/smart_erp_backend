<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StoreRequisitionLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['store_requisition_id', 'item_id', 'unit_id', 'quantity', 'issued_quantity', 'notes'];

    protected $casts = ['quantity' => 'float', 'issued_quantity' => 'float'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function remaining(): float
    {
        return max(0, round($this->quantity - $this->issued_quantity, 4));
    }
}
