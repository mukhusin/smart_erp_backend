<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            /** patient, insurer, company, donor, individual or other. */
            'kind' => ['required', 'in:patient,insurer,company,donor,individual,other'],
            'tin' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
