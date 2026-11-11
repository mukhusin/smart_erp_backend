<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The user's own password, to confirm. */
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return ['password.current_password' => 'The password is incorrect.'];
    }
}
