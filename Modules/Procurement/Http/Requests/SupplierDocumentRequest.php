<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:tax_clearance,business_licence,registration,tin,vat,professional,other'],
            'number' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
