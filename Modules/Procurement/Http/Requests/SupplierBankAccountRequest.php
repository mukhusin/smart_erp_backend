<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierBankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
