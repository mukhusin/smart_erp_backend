<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','name','code','phone','email','address','tax_number','is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
    public function bills()    { return $this->hasMany(Bill::class); }
    public function orders()   { return $this->hasMany(PurchaseOrder::class); }
}
