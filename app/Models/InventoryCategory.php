<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    protected $fillable = ['company_id','name','description'];

    public function company() { return $this->belongsTo(Company::class); }
    public function items()   { return $this->hasMany(InventoryItem::class); }
}
