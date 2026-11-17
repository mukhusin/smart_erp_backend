<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderBidLine extends Model
{

    public $timestamps = false;

    public function tenderLine()
    {
        return $this->belongsTo(TenderLine::class);
    }

    protected $fillable = ['tender_bid_id', 'tender_line_id', 'unit_price'];

    protected $casts = ['unit_price' => 'encrypted'];
}
