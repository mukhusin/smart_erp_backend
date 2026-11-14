<?php

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('company')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($id)],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('companies', 'registration_number')->ignore($id)],
            'tin' => ['sometimes', 'nullable', 'string', 'max:50'],
            /** Phone, email and address are printed in the header of the company's documents, so they cannot be cleared. */
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'fiscal_year_start' => ['sometimes', 'nullable', 'string', 'max:10'],
            /** false = suspended: the company's users can no longer log in. */
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
