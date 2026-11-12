<?php

namespace Modules\Hr\Support;

use App\Models\Company;
use App\Models\LeaveType;
use App\Services\CompanyContext;

/**
 * Leave types a new company starts with (Tanzania, Employment and Labour Relations Act 2004). HR can change them.
 */
final class DefaultLeaveTypes
{
    public const TYPES = [
        ['code' => 'AL', 'name' => 'Annual leave', 'kind' => 'annual', 'max_days' => 28, 'day_count' => 'calendar', 'limit_per' => 'year', 'carry_over_days' => 28, 'notice_days' => 14, 'color' => 'blue'],
        ['code' => 'SL', 'name' => 'Sick leave', 'kind' => 'sick', 'max_days' => 126, 'day_count' => 'calendar', 'limit_per' => 'year', 'requires_document' => true, 'color' => 'red'],
        ['code' => 'ML', 'name' => 'Maternity leave', 'kind' => 'maternity', 'gender' => 'female', 'max_days' => 84, 'day_count' => 'calendar', 'limit_per' => 'request', 'requires_document' => true, 'color' => 'pink'],
        ['code' => 'PL', 'name' => 'Paternity leave', 'kind' => 'paternity', 'gender' => 'male', 'max_days' => 3, 'day_count' => 'working', 'limit_per' => 'request', 'requires_document' => true, 'color' => 'indigo'],
        ['code' => 'CL', 'name' => 'Compassionate leave', 'kind' => 'compassionate', 'max_days' => 4, 'day_count' => 'working', 'limit_per' => 'request', 'color' => 'slate'],
        ['code' => 'UL', 'name' => 'Leave without pay', 'kind' => 'unpaid', 'with_pay' => false, 'max_days' => 30, 'day_count' => 'working', 'limit_per' => 'request', 'notice_days' => 7, 'color' => 'amber'],
    ];

    public static function createForCompany(Company $company): void
    {
        app(CompanyContext::class)->run($company, function () {
            if (LeaveType::withTrashed()->exists()) {
                return;
            }
            foreach (self::TYPES as $type) {
                LeaveType::create($type + ['gender' => 'all', 'with_pay' => true, 'requires_document' => false, 'notice_days' => 0, 'is_active' => true]);
            }
        });
    }
}
