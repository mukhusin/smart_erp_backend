<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

final class Locale
{
    public const SUPPORTED = ['en' => 'English', 'sw' => 'Kiswahili'];

    /** The user's language, else the company default, else English. */
    public static function for(?User $user, ?Company $company = null): string
    {
        $company ??= $user?->company;
        $l = $user?->locale ?: $company?->default_locale;

        return array_key_exists((string) $l, self::SUPPORTED) ? $l : 'en';
    }

    /** Run something (an SMS, a PDF, a notification) in the given person's language. */
    public static function as(?User $user, callable $fn): mixed
    {
        $before = app()->getLocale();
        app()->setLocale(self::for($user));
        try {
            return $fn();
        } finally {
            app()->setLocale($before);
        }
    }
}
