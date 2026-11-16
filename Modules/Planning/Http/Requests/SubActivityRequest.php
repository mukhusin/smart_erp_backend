<?php

namespace Modules\Planning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubActivityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:255'],
            'output' => ['nullable', 'string', 'max:500'],
            'quarters' => ['nullable', 'string', 'regex:/^[1-4](,[1-4]){0,3}$/'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ];
    }
}
