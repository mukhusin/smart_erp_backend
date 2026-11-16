<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentListItem extends Model
{
    protected $fillable = ['payment_list_id', 'payment_voucher_id', 'payment_request_payee_id', 'amount', 'reference'];

    protected $casts = ['amount' => 'float'];

    public function list()
    {
        return $this->belongsTo(PaymentList::class, 'payment_list_id');
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function payee()
    {
        return $this->belongsTo(PaymentRequestPayee::class, 'payment_request_payee_id');
    }
}
