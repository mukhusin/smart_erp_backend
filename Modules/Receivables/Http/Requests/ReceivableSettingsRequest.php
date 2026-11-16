<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivableSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'receivable_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'default_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
