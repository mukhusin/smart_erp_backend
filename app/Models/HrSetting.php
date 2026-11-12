<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'renumber_on_contract_change', 'leave_year_start_month', 'annual_cycle_from_joining', 'joiners_from'];

    protected $casts = ['renumber_on_contract_change' => 'boolean', 'leave_year_start_month' => 'integer', 'annual_cycle_from_joining' => 'boolean', 'joiners_from' => 'date'];

    public static function current(): self
    {
        return static::firstOrNew([], ['renumber_on_contract_change' => false, 'leave_year_start_month' => 1, 'annual_cycle_from_joining' => true]);
    }
}
