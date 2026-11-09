<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id','name','description'];
    public function items()   { return $this->hasMany(InventoryItem::class); }
}
