<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'renumber_on_contract_change'];

    protected $casts = ['renumber_on_contract_change' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['renumber_on_contract_change' => false]);
    }
}
