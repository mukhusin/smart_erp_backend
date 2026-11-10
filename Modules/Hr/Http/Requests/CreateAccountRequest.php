<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Defaults to the employee's email. */
            'username' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('users', 'username')],
        ];
    }
}
