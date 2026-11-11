<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LicenceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            /** The body that issues it, e.g. Medical Council of Tanganyika. */
            'provider_name' => ['nullable', 'string', 'max:150'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }
}
