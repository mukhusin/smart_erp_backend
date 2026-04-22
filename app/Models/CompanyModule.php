<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyModule extends Model
{
    protected $table = 'company_modules';
    protected $fillable = ['company_id','module_slug','is_enabled','enabled_at','expires_at'];
    protected $casts = ['is_enabled' => 'boolean', 'enabled_at' => 'datetime', 'expires_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function module()  { return $this->belongsTo(ErpModule::class, 'module_slug', 'slug'); }
}
