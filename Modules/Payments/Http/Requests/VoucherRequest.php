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
            /** VAT on the supplier's tax invoice, claimed as input VAT (VAT-registered companies). For a supplier bill from goods received it comes from the recorded supplier invoice. */
            'input_vat' => ['nullable', 'numeric', 'min:0'],
            'vat_invoice_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
