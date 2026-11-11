<?php

namespace Modules\Core\Sms;

final class PhoneNumber
{
    /** A Tanzanian mobile number as 2557XXXXXXXX / 2556XXXXXXXX, or null when it is not one. */
    public static function tanzania(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^0([67]\d{8})$/', $digits, $m) || preg_match('/^([67]\d{8})$/', $digits, $m)) {
            $digits = '255'.$m[1];
        }

        return preg_match('/^255[67]\d{8}$/', $digits) ? $digits : null;
    }
}
