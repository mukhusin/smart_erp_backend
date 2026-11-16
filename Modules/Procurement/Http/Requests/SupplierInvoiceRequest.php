<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['supplier_invoice' => ['required', 'string', 'max:100'], 'invoice_date' => ['required', 'date'], 'invoice_amount' => ['required', 'numeric', 'min:0']];
    }
}
