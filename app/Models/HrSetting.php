<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'renumber_on_contract_change', 'leave_year_start_month', 'annual_cycle_from_joining', 'joiners_from', 'hospital_patients', 'retirement_age', 'task_max_step', 'profile_required', 'profile_min_percent', 'profile_checks'];

    protected $casts = ['renumber_on_contract_change' => 'boolean', 'leave_year_start_month' => 'integer', 'annual_cycle_from_joining' => 'boolean', 'joiners_from' => 'date', 'hospital_patients' => 'boolean', 'retirement_age' => 'integer', 'task_max_step' => 'integer', 'profile_required' => 'boolean', 'profile_min_percent' => 'integer', 'profile_checks' => 'array'];

    public static function current(): self
    {
        return static::firstOrNew([], ['renumber_on_contract_change' => false, 'leave_year_start_month' => 1, 'annual_cycle_from_joining' => true, 'hospital_patients' => false, 'profile_required' => false, 'profile_min_percent' => 75]);
    }
}
