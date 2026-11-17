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
            /** Asset account for staff advances (imprest). */
            'imprest_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            /** Most days allowed between the money being needed and retirement. */
            'imprest_retire_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            /** A staff member may hold only one imprest at a time. */
            'imprest_one_open' => ['sometimes', 'boolean'],
            'require_attachment' => ['required', 'boolean'],
        ];
    }
}
