<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['quotation_id', 'purchase_requisition_line_id', 'unit_price', 'quantity', 'amount', 'awarded', 'remarks'];

    protected $casts = ['unit_price' => 'float', 'quantity' => 'float', 'amount' => 'float', 'awarded' => 'boolean'];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function requisitionLine()
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }
}
