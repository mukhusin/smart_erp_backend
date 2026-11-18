<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Models\PaymentRequest;
use Modules\Procurement\Models\Supplier;

class FuelRecharge extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['ordered' => 'Ordered', 'received' => 'Delivered', 'cancelled' => 'Cancelled'];

    protected $table = 'fleet_fuel_recharges';

    protected $fillable = ['company_id', 'number', 'fleet_fuel_store_id', 'supplier_id', 'date', 'litres', 'unit_price', 'amount', 'status', 'received_litres', 'received_on',
        'delivery_note', 'payment_request_id', 'created_by'];

    protected $casts = ['date' => 'date', 'received_on' => 'date', 'litres' => 'float', 'unit_price' => 'float', 'amount' => 'float', 'received_litres' => 'float'];

    public function store()
    {
        return $this->belongsTo(FuelStore::class, 'fleet_fuel_store_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
