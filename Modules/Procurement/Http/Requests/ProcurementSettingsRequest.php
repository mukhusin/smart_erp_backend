<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcurementSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'clearing_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'direct_limit' => ['required', 'numeric', 'min:0'],
            'min_quotes' => ['required', 'integer', 'min:1', 'max:10'],
            'match_tolerance_percent' => ['required', 'numeric', 'min:0', 'max:20'],
        ];
    }
}
