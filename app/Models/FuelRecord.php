<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FuelRecord extends Model
{
    protected $fillable = [
        'vehicle_id','date','liters','cost_per_liter','total_cost','odometer_reading','filled_by','notes',
    ];

    protected $casts = [
        'date'            => 'date',
        'liters'          => 'decimal:2',
        'cost_per_liter'  => 'decimal:2',
        'total_cost'      => 'decimal:2',
    ];

    public function vehicle()  { return $this->belongsTo(Vehicle::class); }
    public function filledBy() { return $this->belongsTo(Employee::class, 'filled_by'); }
}
