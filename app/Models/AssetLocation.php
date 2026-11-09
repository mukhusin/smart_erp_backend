<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetLocation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id','name','description'];
    public function assets()  { return $this->hasMany(Asset::class); }
}
