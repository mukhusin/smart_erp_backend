<?php

namespace Modules\Appraisals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppraisalCycleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'mid_year_date' => ['nullable', 'date', 'after:start_date', 'before:end_date'],
            'self_due' => ['nullable', 'date', 'after_or_equal:start_date'],
            'review_due' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
