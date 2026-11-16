<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlacklistRequest extends FormRequest
{
    public function rules(): array
    {
        return ['blacklisted' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'], 'until' => ['nullable', 'date', 'after:today']];
    }
}
