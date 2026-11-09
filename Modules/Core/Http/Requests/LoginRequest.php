<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Username or email address. */
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:100'],
        ];
    }
}
