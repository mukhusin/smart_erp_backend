<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemStoreLevel extends Model
{
    public $timestamps = false;

    protected $fillable = ['item_id', 'store_id', 'min_level', 'max_level', 'reorder_level', 'store_bin_id'];

    protected $casts = ['min_level' => 'float', 'max_level' => 'float', 'reorder_level' => 'float'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function bin()
    {
        return $this->belongsTo(StoreBin::class, 'store_bin_id');
    }
}
