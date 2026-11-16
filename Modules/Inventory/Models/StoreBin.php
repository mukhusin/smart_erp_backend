<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StoreBin extends Model
{
    public $timestamps = false;

    protected $fillable = ['store_id', 'parent_id', 'code', 'name'];

    public function parent()
    {
        return $this->belongsTo(StoreBin::class, 'parent_id');
    }
}
