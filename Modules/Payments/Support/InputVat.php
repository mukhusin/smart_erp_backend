<?php

namespace Modules\Payments\Support;

use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\VatSetting;

final class InputVat
{
    /** Validates input VAT that is part of an amount; returns it rounded. */
    public static function check(float $vat, float $amount, string $field): float
    {
        $vat = round($vat, 2);
        if ($vat <= 0) {
            return 0.0;
        }
        VatSetting::ready('input') ?? throw ValidationException::withMessages([$field => 'Input VAT is claimed only when the company is VAT registered (Accounting → VAT).']);
        if ($vat >= round($amount, 2)) {
            throw ValidationException::withMessages([$field => 'The VAT must be less than the amount it is part of.']);
        }

        return $vat;
    }

    /** @return list<array<string, mixed>> */
    public static function line(float $vat, ?string $invoice): array
    {
        return $vat > 0 ? [['account_id' => VatSetting::current()->input_account_id, 'debit' => round($vat, 2), 'description' => 'Input VAT'.($invoice ? " {$invoice}" : '')]] : [];
    }
}
