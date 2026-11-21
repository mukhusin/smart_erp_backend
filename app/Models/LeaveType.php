<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    public const KINDS = [
        'annual' => 'Annual leave', 'sick' => 'Sick leave', 'maternity' => 'Maternity leave', 'paternity' => 'Paternity leave',
        'compassionate' => 'Compassionate leave', 'study' => 'Study leave', 'unpaid' => 'Unpaid leave', 'other' => 'Other',
    ];

    /** Longest leave: a year for yearly balances, five years each time (e.g. study leave). */
    public const MAX_YEARLY_DAYS = 366;

    public const MAX_REQUEST_DAYS = 1830;

    public const PLACE_RULES = ['outside' => 'Outside the home region', 'within' => 'Within the home region'];

    public const DAY_COUNTS = [
        'working' => 'Working days (days off and public holidays not counted)',
        'calendar_no_holidays' => 'Calendar days, public holidays not counted',
        'calendar' => 'Calendar days (every day counted)',
    ];

    protected $fillable = [
        'company_id', 'name', 'code', 'kind', 'gender', 'description', 'with_pay', 'max_days', 'fixed_days', 'hide_days', 'place_rule', 'day_count', 'limit_per',
        'carry_over_days', 'notice_days', 'requires_document', 'employee_types', 'color', 'is_active',
    ];

    protected $casts = [
        'with_pay' => 'boolean', 'fixed_days' => 'boolean', 'hide_days' => 'boolean', 'is_active' => 'boolean', 'requires_document' => 'boolean', 'employee_types' => 'array',
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
