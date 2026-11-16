<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** failed (the bank did not pay) or returned (the payee sent the money back). */
            'status' => ['required', 'in:failed,returned'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
