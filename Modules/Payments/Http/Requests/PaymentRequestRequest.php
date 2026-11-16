<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:200'],
            'purpose' => ['nullable', 'string', 'max:3000'],
            'needed_by' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'payees' => ['required', 'array', 'min:1', 'max:200'],
            'payees.*.name' => ['required', 'string', 'max:150'],
            /** supplier, staff or other. */
            'payees.*.kind' => ['nullable', 'in:supplier,staff,other'],
            'payees.*.employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            /** bank, mobile, cheque or cash. */
            'payees.*.method' => ['required', 'in:bank,mobile,cheque,cash'],
            'payees.*.bank_name' => ['nullable', 'string', 'max:100'],
            'payees.*.account_name' => ['nullable', 'string', 'max:150'],
            'payees.*.account_number' => ['nullable', 'string', 'max:50'],
            'payees.*.mobile_number' => ['nullable', 'string', 'max:20'],
            'payees.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
        ];
    }
}
