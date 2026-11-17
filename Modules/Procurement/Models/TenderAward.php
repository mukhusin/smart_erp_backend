<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderAward extends Model
{

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function bid()
    {
        return $this->belongsTo(TenderBid::class, 'tender_bid_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function contract()
    {
        return $this->hasOne(Contract::class);
    }

    protected $fillable = ['tender_id', 'lot', 'tender_bid_id', 'supplier_id', 'amount', 'letter_sent_at', 'accept_by', 'accepted_at', 'declined_at'];

    protected $casts = ['amount' => 'float', 'letter_sent_at' => 'datetime', 'accept_by' => 'date', 'accepted_at' => 'datetime', 'declined_at' => 'datetime'];
}
