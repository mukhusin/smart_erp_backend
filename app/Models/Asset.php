<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','asset_category_id','department_id','asset_location_id','employee_id',
        'name','model','manufacturer','serial_number','barcode',
        'cost','purchase_date','warranty_expiration_date',
        'status','description','cover_image',
    ];

    protected $casts = [
        'cost'                    => 'decimal:2',
        'purchase_date'           => 'date',
        'warranty_expiration_date'=> 'date',
    ];

    const STATUSES = ['available','in-use','maintenance','disposed'];
    public function category()   { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function location()   { return $this->belongsTo(AssetLocation::class, 'asset_location_id'); }
    public function employee()   { return $this->belongsTo(Employee::class); }
}
