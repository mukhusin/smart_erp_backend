<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of allowance and how it is earned. `duration` is the unit: month, day (working day), night (night shift),
 * holiday (public holiday worked) or case (counted items, e.g. theatre cases). `generation`: auto (from the working
 * calendar), claim (entered with a count and approved), assigned (monthly amount per employee) or activity (per case: one
 * daily count, entered or fetched from another system, times the rate for everyone the rate covers).
 */
class AllowanceCategory extends Model
{
    use Auditable, BelongsToCompany;

    public const UNITS = [
        'month' => 'Per month', 'day' => 'Per working day', 'night' => 'Per night shift', 'holiday' => 'Per public holiday worked', 'case' => 'Per case or item',
    ];

    public const SOURCES = ['manual' => 'Entered by hand', 'rest' => 'Fetched from another system (REST)'];

    protected $fillable = ['company_id', 'category', 'duration', 'generation', 'activity_source', 'activity_config', 'code', 'claimed', 'deduct_leave', 'other_absence', 'taxable', 'description', 'is_active'];

    protected $hidden = ['activity_config'];

    protected $casts = ['activity_config' => 'encrypted:array', 'claimed' => 'boolean', 'deduct_leave' => 'boolean', 'other_absence' => 'boolean', 'taxable' => 'boolean', 'is_active' => 'boolean'];

    public function departmentAllowances()
    {
        return $this->hasMany(DepartmentAllowance::class, 'category_id');
    }

    public function activity(): bool
    {
        return $this->generation === 'activity' && $this->duration === 'case';
    }

    public function auto(): bool
    {
        return $this->generation === 'auto' && $this->duration !== 'case';
    }
}
