<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetirementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            /** Where money not spent was returned (needed when anything is returned). */
            'cash_bank_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'return_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
