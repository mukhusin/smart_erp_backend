<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            /** bank, mobile, cheque or cash. */
            'method' => ['required', 'in:bank,mobile,cheque,cash'],
            'date' => ['required', 'date'],
            'voucher_ids' => ['required', 'array', 'min:1', 'max:500'],
            'voucher_ids.*' => ['integer'],
        ];
    }
}
