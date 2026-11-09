<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** From the invite link. */
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            /** "invite" (default, link valid 3 days) or "reset" (forgot password, link valid 60 minutes). */
            'purpose' => ['sometimes', 'in:invite,reset'],
        ];
    }
}
