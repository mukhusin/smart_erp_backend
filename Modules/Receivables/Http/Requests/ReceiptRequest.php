<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** invoice (pays invoices of a customer) or direct (no invoice: cash sale, donation, other income). */
            'kind' => ['required', 'in:invoice,direct'],
            'customer_id' => ['nullable', 'integer'],
            'payer_name' => ['nullable', 'string', 'max:200'],
            'date' => ['required', 'date'],
            'cash_bank_account_id' => ['required', 'integer'],
            /** cash, bank, mobile, cheque or card. */
            'method' => ['required', 'in:cash,bank,mobile,cheque,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'lines.*.budget_item_id' => ['nullable', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            /** VAT code; the amount received includes the VAT. Ignored when the company is not VAT registered. */
            'lines.*.tax_code_id' => ['nullable', 'integer'],
        ];
    }
}
