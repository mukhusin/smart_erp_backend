<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class YearEndSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Equity account that receives each year's result (retained earnings or accumulated fund). */
            'retained_earnings_account_id' => ['required', 'integer'],
        ];
    }
}
