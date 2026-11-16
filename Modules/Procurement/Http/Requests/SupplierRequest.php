<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            /** company, individual, government or ngo. */
            'kind' => ['required', 'in:company,individual,government,ngo'],
            'tin' => ['nullable', 'string', 'max:30'],
            'vrn' => ['nullable', 'string', 'max:30'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            /** women, youth, disability, elderly or local. */
            'special_group' => ['nullable', 'in:women,youth,disability,elderly,local'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
