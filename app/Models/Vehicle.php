<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','plate_number','make','model','year','color','type',
        'status','current_driver_id','purchase_date','purchase_cost',
        'insurance_expiry','inspection_expiry','description',
    ];

    protected $casts = [
        'purchase_date'     => 'date',
        'insurance_expiry'  => 'date',
        'inspection_expiry' => 'date',
        'purchase_cost'     => 'decimal:2',
    ];

    const TYPES    = ['sedan','suv','truck','bus','van','motorcycle','other'];
    const STATUSES = ['available','in-use','maintenance','disposed'];
    public function currentDriver() { return $this->belongsTo(Employee::class, 'current_driver_id'); }
    public function fuelRecords()   { return $this->hasMany(FuelRecord::class); }
    public function maintenanceLogs(){ return $this->hasMany(VehicleMaintenanceLog::class); }
}
