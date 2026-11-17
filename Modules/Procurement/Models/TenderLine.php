<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderLine extends Model
{

    public $timestamps = false;

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function requisitionLine()
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    protected $fillable = ['tender_id', 'purchase_requisition_line_id', 'lot', 'quantity'];

    protected $casts = ['quantity' => 'float'];
}
