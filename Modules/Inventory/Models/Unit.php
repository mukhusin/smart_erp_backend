<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
