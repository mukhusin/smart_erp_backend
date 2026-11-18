<?php

namespace Modules\Accounting\Support;

use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\TaxCode;
use Modules\Accounting\Models\VatSetting;

final class Vat
{
    /** @return array{0: int|null, 1: float} code id and rate; no code when the company is not VAT registered. */
    public static function code(?int $codeId, string $field): array
    {
        if (! $codeId || ! VatSetting::registered()) {
            return [null, 0.0];
        }
        $code = TaxCode::where('is_active', true)->find($codeId);
        if (! $code) {
            throw ValidationException::withMessages([$field => 'Choose an active VAT code.']);
        }

        return [$code->id, $code->kind === 'standard' ? (float) $code->rate : 0.0];
    }

    public static function onNet(float $net, float $rate): float
    {
        return round($net * $rate / 100, 2);
    }

    public static function inGross(float $gross, float $rate): float
    {
        return round($gross * $rate / (100 + $rate), 2);
    }
}
