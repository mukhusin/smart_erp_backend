<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'references' => ['required', 'array'],
            'references.*.id' => ['required', 'integer'],
            'references.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
