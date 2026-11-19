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
        $company ??= $user?->company ?? (($id = app(\App\Services\CompanyContext::class)->id()) ? Company::find($id) : null);
        $l = $user?->locale ?: $company?->default_locale;

        return array_key_exists((string) $l, self::SUPPORTED) ? $l : 'en';
    }

    /** A stored notification's title or message in the current language (from its English template and values). */
    public static function render(array $data, string $field): string
    {
        $t = $data['t'] ?? null;
        if (is_array($t) && isset($t[$field])) {
            return (string) __($t[$field], $t['params'] ?? []);
        }

        return (string) __((string) ($data[$field] ?? ''));
    }

    /** An English template in the person's language (for SMS and email to staff with or without an account). */
    public static function text(?User $user, string $template, array $params = []): string
    {
        return (string) trans($template, $params, self::for($user));
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
