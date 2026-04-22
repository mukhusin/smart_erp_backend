<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class VehicleMaintenanceLog extends Model
{
    protected $fillable = [
        'vehicle_id','date','type','description','cost','service_provider','next_service_date','done_by',
    ];

    protected $casts = [
        'date'             => 'date',
        'next_service_date'=> 'date',
        'cost'             => 'decimal:2',
    ];

    const TYPES = ['routine','repair','inspection','tyre','battery','other'];

    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function doneBy()  { return $this->belongsTo(Employee::class, 'done_by'); }
}
