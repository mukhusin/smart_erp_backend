<?php

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CeilingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Most the department may budget; empty for no ceiling. */
            'ceiling' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
