<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\HrSetting;
use App\Models\User;

final class ProfileCompletion
{
    /** What can count towards a complete profile; HR chooses which ones count. */
    public const CHECKS = [
        'contact' => 'Phone number and home address',
        'next_of_kin' => 'Next of kin',
        'dependants' => 'Dependants',
        'qualifications' => 'Qualifications with certificate',
        'documents' => 'Documents (ID, certificates)',
        'experiences' => 'Work experience',
        'licences' => 'Professional licence',
        'personal' => 'Personal details (entered by HR)',
    ];

    public const DEFAULT_CHECKS = ['contact', 'next_of_kin', 'qualifications', 'documents', 'dependants'];

    /** @return list<string> */
    public static function checks(): array
    {
        $chosen = HrSetting::current()->profile_checks;

        return array_values(array_intersect(array_keys(self::CHECKS), $chosen ?: self::DEFAULT_CHECKS));
    }

    /** @return array{percent: int, items: list<array{key: string, label: string, done: bool}>} */
    public static function of(Employee $e): array
    {
        $kept = fn ($q) => $q->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'rejected'));
        $done = [
            'contact' => filled($e->phone_number) && filled($e->home_address),
            'next_of_kin' => fn () => $kept($e->dependants())->where('is_next_of_kin', true)->exists(),
            'dependants' => fn () => $kept($e->dependants())->exists(),
            'qualifications' => fn () => $kept($e->qualifications())->whereNotNull('certificate')->exists(),
            'documents' => fn () => $kept($e->documents())->exists(),
            'experiences' => fn () => $kept($e->experiences())->exists(),
            'licences' => fn () => $kept($e->licences())->exists(),
            'personal' => filled($e->date_of_birth) && filled($e->gender) && filled($e->marital_status) && filled($e->nationality) && filled($e->id_number),
        ];
        $items = array_map(fn ($k) => ['key' => $k, 'label' => self::CHECKS[$k], 'done' => (bool) value($done[$k])], self::checks());
        $percent = $items ? (int) floor(100 * count(array_filter($items, fn ($i) => $i['done'])) / count($items)) : 100;

        return ['percent' => $percent, 'items' => $items];
    }

    /** @return array{required: bool, complete: bool, percent: int, min_percent: int, items: list<array{key: string, label: string, done: bool}>}|null */
    public static function gate(User $user): ?array
    {
        $s = HrSetting::current();
        if (! $s->profile_required || ! $user->company_id || $user->hasRole('company-admin')) {
            return null;
        }
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee || ! app(\Modules\Core\Services\AccessService::class)->canUseModule($user, 'self-service') || ! $user->can('self-service.use')) {
            return null;
        }
        $p = self::of($employee);
        $min = (int) ($s->profile_min_percent ?: 75);

        return ['required' => true, 'complete' => $p['percent'] >= $min, 'percent' => $p['percent'], 'min_percent' => $min, 'items' => $p['items']];
    }
}
