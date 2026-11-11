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

    public const EDUCATION_LEVELS = [
        'o_level' => 'Ordinary Level (O-Level)',
        'a_level' => 'Advanced Level (A-Level)',
        'certificate' => 'Certificate',
        'ordinary_diploma' => 'Ordinary Diploma',
        'advanced_diploma' => 'Advanced Diploma',
        'degree' => 'Degree',
        'masters' => 'Masters',
        'phd' => 'Doctor of Philosophy (PhD)',
        'professor' => 'Professor',
    ];

    public const DOCUMENT_TYPES = [
        'birth_certificate' => 'Birth certificate',
        'id_copy' => 'ID copy',
        'registration_certificate' => 'Registration certificate',
        'tin_certificate' => 'TIN certificate',
        'marriage_certificate' => 'Marriage certificate',
        'academic_certificate' => 'Academic certificate',
        'professional_certificate' => 'Professional certificate',
        'cv' => 'CV',
        'other' => 'Other',
    ];

    public const RELATIONSHIPS = [
        'spouse' => 'Spouse',
        'child' => 'Child',
        'father' => 'Father',
        'mother' => 'Mother',
    ];

    /** Relationships an employee can have more than one of. */
    public const MULTIPLE_RELATIONSHIPS = ['child'];

    public const CONTRACT_STATUSES = [
        'waiting' => 'Waiting to start',
        'active' => 'Active',
    ];

    /** Ways a contract ends. All except `ended` and `change_of_contract` mean the employee leaves. */
    public const CONTRACT_END_REASONS = [
        'ended' => 'Contract ended',
        'change_of_contract' => 'Change of contract',
        'resigned_24_hrs_notes' => 'Resignation, 24 hours notice',
        'resigned_30_days_notes' => 'Resignation, 30 days notice',
        'terminated_24_hrs_notes' => 'Terminated, 24 hours notice',
        'terminated_30_days_notes' => 'Terminated, 30 days notice',
        'mutual_agreement' => 'Mutual agreement',
        'retrenchment' => 'Retrenchment',
        'retired' => 'Retired',
        'deceased' => 'Deceased',
        'abscondment' => 'Abscondment',
    ];

    public const CONTRACT_KEEPS_EMPLOYEE = ['ended', 'change_of_contract'];

    /** Days before a contract or licence ends when it shows as ending soon and a renewal can be added. */
    public const RENEWAL_WINDOW_DAYS = 90;

    public static function contractStatusLabel(?string $status): ?string
    {
        return self::CONTRACT_STATUSES[$status] ?? self::CONTRACT_END_REASONS[$status] ?? $status;
    }

    /** `employment_type` of a contract type ("FULL TIME" in HRMS data) as an employee `emp_type`. */
    public static function empTypeOf(?string $employmentType): ?string
    {
        $key = str_replace([' ', '-'], '_', strtolower(trim((string) $employmentType)));

        return array_key_exists($key, self::EMPLOYMENT_TYPES) ? $key : null;
    }

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
            'education_levels' => $list(self::EDUCATION_LEVELS),
            'document_types' => $list(self::DOCUMENT_TYPES),
            'relationships' => $list(self::RELATIONSHIPS),
            'contract_end_reasons' => $list(self::CONTRACT_END_REASONS),
        ];
    }

    public static function keys(array $options): string
    {
        return 'in:' . implode(',', array_keys($options));
    }
}
