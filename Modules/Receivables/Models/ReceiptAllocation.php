<?php

namespace Modules\Receivables\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptAllocation extends Model
{
    protected $fillable = ['receipt_id', 'invoice_id', 'amount'];

    protected $casts = ['amount' => 'float'];

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
