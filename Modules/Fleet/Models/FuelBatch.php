<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Models\PaymentRequest;

class FuelBatch extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'fleet_fuel_batches';

    protected $fillable = ['company_id', 'number', 'fleet_fuel_store_id', 'from_date', 'to_date', 'litres', 'total', 'payment_request_id', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'litres' => 'float', 'total' => 'float'];

    public function store()
    {
        return $this->belongsTo(FuelStore::class, 'fleet_fuel_store_id');
    }

    public function requests()
    {
        return $this->hasMany(FuelRequest::class, 'fleet_fuel_batch_id');
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
