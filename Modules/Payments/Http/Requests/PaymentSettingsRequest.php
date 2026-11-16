<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payable_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'withholding_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'require_attachment' => ['required', 'boolean'],
        ];
    }
}
