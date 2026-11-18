<?php

namespace Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FuelMovement extends Model
{
    public const TYPES = ['recharge' => 'Delivered', 'issue' => 'Issued', 'adjust' => 'Stock count adjustment'];

    protected $table = 'fleet_fuel_movements';

    protected $fillable = ['fleet_fuel_store_id', 'date', 'type', 'litres', 'unit_price', 'amount', 'fleet_fuel_request_id', 'fleet_fuel_recharge_id', 'note', 'created_by'];

    protected $casts = ['date' => 'date', 'litres' => 'float', 'unit_price' => 'float', 'amount' => 'float'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
