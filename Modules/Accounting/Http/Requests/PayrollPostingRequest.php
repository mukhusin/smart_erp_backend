<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Models\PayrollPostingSetting;

class PayrollPostingRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['enabled' => ['required', 'boolean']];
        foreach (array_keys(PayrollPostingSetting::ACCOUNTS) as $key) {
            $rules["{$key}_account_id"] = ['nullable', 'integer', 'exists:accounts,id'];
        }

        return $rules;
    }
}
