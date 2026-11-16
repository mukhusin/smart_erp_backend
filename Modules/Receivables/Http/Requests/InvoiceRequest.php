<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'lines.*.budget_item_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
