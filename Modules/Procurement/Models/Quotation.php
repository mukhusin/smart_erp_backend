<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = ['quotation_request_id', 'supplier_id', 'reference', 'valid_until', 'delivery_days', 'notes', 'responsive', 'path', 'file_name', 'total'];

    protected $casts = ['valid_until' => 'date', 'responsive' => 'boolean', 'total' => 'float', 'delivery_days' => 'integer'];

    public function request()
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(QuotationLine::class);
    }
}
