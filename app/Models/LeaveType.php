<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const KINDS = [
        'annual' => 'Annual leave', 'sick' => 'Sick leave', 'maternity' => 'Maternity leave', 'paternity' => 'Paternity leave',
        'compassionate' => 'Compassionate leave', 'study' => 'Study leave', 'unpaid' => 'Unpaid leave', 'other' => 'Other',
    ];

    public const DAY_COUNTS = [
        'working' => 'Working days (days off and public holidays not counted)',
        'calendar_no_holidays' => 'Calendar days, public holidays not counted',
        'calendar' => 'Calendar days (every day counted)',
    ];

    protected $fillable = [
        'company_id', 'name', 'code', 'kind', 'gender', 'description', 'with_pay', 'max_days', 'day_count', 'limit_per',
        'carry_over_days', 'notice_days', 'requires_document', 'employee_types', 'color', 'is_active',
    ];

    protected $casts = [
        'with_pay' => 'boolean', 'is_active' => 'boolean', 'requires_document' => 'boolean', 'employee_types' => 'array',
        'max_days' => 'integer', 'carry_over_days' => 'integer', 'notice_days' => 'integer',
    ];

    public function leaves()
    {
        return $this->hasMany(EmpLeave::class);
    }

    public function isAnnual(): bool
    {
        return $this->kind === 'annual';
    }

    public function yearly(): bool
    {
        return $this->limit_per === 'year';
    }
}
