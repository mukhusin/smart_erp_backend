<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['supplier_invoice' => ['required', 'string', 'max:100'], 'invoice_date' => ['required', 'date'], 'invoice_amount' => ['required', 'numeric', 'min:0'],
            /** VAT included in the invoice amount; claimed as input VAT when the company is VAT registered. */
            'invoice_vat' => ['nullable', 'numeric', 'min:0', 'lte:invoice_amount']];
    }
}
