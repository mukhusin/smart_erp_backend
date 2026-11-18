<?php

namespace Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Models\PaymentRequest;

class HirePayment extends Model
{
    protected $table = 'fleet_hire_payments';

    protected $fillable = ['fleet_hire_id', 'from_date', 'to_date', 'quantity', 'amount', 'payment_request_id', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'quantity' => 'float', 'amount' => 'float'];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
