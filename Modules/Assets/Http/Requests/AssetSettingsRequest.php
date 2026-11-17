<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\AssetSetting;

class AssetSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** {CAT} category code, {YEAR} acquisition year, {SEQ} running number. */
            'number_pattern' => ['required', 'string', 'max:60', 'regex:/\{SEQ\}/'],
            'depreciation_start' => ['required', Rule::in(array_keys(AssetSetting::START))],
            'opening_account_id' => ['nullable', 'integer'],
            'donation_account_id' => ['nullable', 'integer'],
            'gain_loss_account_id' => ['nullable', 'integer'],
            'revaluation_account_id' => ['nullable', 'integer'],
            'impairment_account_id' => ['nullable', 'integer'],
            /** Month in which staff confirm the assets they hold (1–12), empty for none. */
            'confirmation_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return ['number_pattern.regex' => 'The pattern must contain {SEQ}.'];
    }
}
