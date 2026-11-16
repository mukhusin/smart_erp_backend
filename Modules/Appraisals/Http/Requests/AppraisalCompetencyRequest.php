<?php

namespace Modules\Appraisals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppraisalCompetencyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ];
    }
}
