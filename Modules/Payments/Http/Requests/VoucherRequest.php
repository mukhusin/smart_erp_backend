<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoucherRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'withholding' => ['nullable', 'array'],
            'withholding.*.payee_id' => ['required', 'integer'],
            'withholding.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
