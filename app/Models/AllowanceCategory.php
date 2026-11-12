<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of allowance and how it is earned. `duration` is the unit: month, day (working day), night (night shift),
 * holiday (public holiday worked) or case (counted items, e.g. theatre cases). `generation`: auto (from the working
 * calendar) or claim (entered with a count and approved).
 */
class AllowanceCategory extends Model
{
    use BelongsToCompany;

    public const UNITS = [
        'month' => 'Per month', 'day' => 'Per working day', 'night' => 'Per night shift', 'holiday' => 'Per public holiday worked', 'case' => 'Per case or item',
    ];

    protected $fillable = ['company_id', 'category', 'duration', 'generation', 'code', 'claimed', 'deduct_leave', 'other_absence', 'taxable', 'description', 'is_active'];

    protected $casts = ['claimed' => 'boolean', 'deduct_leave' => 'boolean', 'other_absence' => 'boolean', 'taxable' => 'boolean', 'is_active' => 'boolean'];

    public function departmentAllowances()
    {
        return $this->hasMany(DepartmentAllowance::class, 'category_id');
    }

    public function auto(): bool
    {
        return $this->generation === 'auto' && $this->duration !== 'case';
    }
}
