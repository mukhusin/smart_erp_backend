<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayrollPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return ['cash_bank_account_id' => ['required', 'integer'], 'date' => ['required', 'date']];
    }
}
