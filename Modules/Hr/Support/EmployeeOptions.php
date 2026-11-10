<?php

namespace Modules\Hr\Support;

/** Fixed choices for employee fields: stored value => label. HRMS values are mapped in hrms-migration-mapping.md. */
final class EmployeeOptions
{
    public const GENDERS = ['Female' => 'Female', 'Male' => 'Male'];

    public const EMPLOYMENT_STATUSES = [
        'probation' => 'On probation',
        'confirmed' => 'Confirmed',
        'terminated' => 'Terminated',
    ];

    public const EMPLOYMENT_TYPES = [
        'full_time' => 'Full time',
        'part_time' => 'Part time',
        'internship' => 'Internship',
        'resident' => 'Resident',
        'volunteer' => 'Volunteer',
        'indirect' => 'Indirect employee',
    ];

    public const CATEGORIES = ['Primary' => 'Primary', 'Secondary' => 'Secondary'];

    public const ID_TYPES = [
        'nida' => 'National ID (NIDA)',
        'passport' => 'Passport',
        'voter_id' => 'Voter ID',
        'driving_licence' => 'Driving licence',
    ];

    public const MARITAL_STATUSES = [
        'single' => 'Single',
        'married' => 'Married',
        'divorced' => 'Divorced',
        'separated' => 'Separated',
        'widowed' => 'Widowed',
    ];

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function all(): array
    {
        $list = fn (array $o) => array_map(fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($o), $o);

        return [
            'genders' => $list(self::GENDERS),
            'employment_statuses' => $list(self::EMPLOYMENT_STATUSES),
            'employment_types' => $list(self::EMPLOYMENT_TYPES),
            'categories' => $list(self::CATEGORIES),
            'id_types' => $list(self::ID_TYPES),
            'marital_statuses' => $list(self::MARITAL_STATUSES),
        ];
    }

    public static function keys(array $options): string
    {
        return 'in:' . implode(',', array_keys($options));
    }
}
