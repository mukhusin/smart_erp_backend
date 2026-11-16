<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemUnit extends Model
{
    public $timestamps = false;

    protected $fillable = ['item_id', 'unit_id', 'factor'];

    protected $casts = ['factor' => 'float'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
