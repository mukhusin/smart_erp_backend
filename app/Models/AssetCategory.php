<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    protected $fillable = ['company_id','name','description'];

    public function company() { return $this->belongsTo(Company::class); }
    public function assets()  { return $this->hasMany(Asset::class); }
}
