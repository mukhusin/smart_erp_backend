<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErpModule extends Model
{
    protected $table = 'erp_modules';
    protected $fillable = ['slug','name','description','icon','color','route_name','category','sort_order','is_core','is_active'];
    protected $casts = ['is_core' => 'boolean', 'is_active' => 'boolean'];

    public function companyModules()
    {
        return $this->hasMany(CompanyModule::class, 'module_slug', 'slug');
    }
}
