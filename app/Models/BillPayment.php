<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class BillPayment extends Model
{
    protected $fillable = [
        'bill_id','payment_date','amount','payment_method','reference','notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    const METHODS = ['cash','bank_transfer','cheque','mobile_money','other'];

    public function bill() { return $this->belongsTo(Bill::class); }
}
