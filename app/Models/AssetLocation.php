<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AssetLocation extends Model
{
    protected $fillable = ['company_id','name','description'];

    public function company() { return $this->belongsTo(Company::class); }
    public function assets()  { return $this->hasMany(Asset::class); }
}
