<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderBid extends Model
{

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(TenderBidLine::class);
    }

    public function scores()
    {
        return $this->hasMany(TenderScore::class);
    }

    /** Price of the lines of one lot (or all), after the bid discount. */
    public function priceFor(?string $lot = null): float
    {
        $this->loadMissing('lines.tenderLine');
        $sum = $this->lines->filter(fn ($l) => $lot === null || $l->tenderLine->lot === $lot)->sum(fn ($l) => (float) $l->unit_price * $l->tenderLine->quantity);

        return round($sum * (1 - $this->discount_percent / 100), 2);
    }

    protected $fillable = ['tender_id', 'supplier_id', 'received_at', 'currency', 'discount_percent', 'valid_until', 'bid_security', 'documents_complete', 'missing_documents', 'notes', 'path', 'file_name', 'read_out_total', 'responsive', 'technical_score', 'financial_score', 'combined_score', 'rank', 'withdrawn'];

    protected $casts = ['received_at' => 'datetime', 'valid_until' => 'date', 'discount_percent' => 'float', 'documents_complete' => 'boolean', 'read_out_total' => 'float', 'responsive' => 'boolean', 'technical_score' => 'float', 'financial_score' => 'float', 'combined_score' => 'float', 'withdrawn' => 'boolean'];
}
