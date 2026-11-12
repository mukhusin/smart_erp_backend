<?php

namespace Modules\Hr\Http\Requests\Roster;

use Illuminate\Foundation\Http\FormRequest;

class PublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            /** yearly: same day every year; once: on one date. */
            'type' => ['required', 'in:yearly,once'],
            'day' => ['required_if:type,yearly', 'nullable', 'integer', 'between:1,31'],
            'month' => ['required_if:type,yearly', 'nullable', 'integer', 'between:1,12'],
            'date' => ['required_if:type,once', 'nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['day.required_if' => 'Choose the day.', 'month.required_if' => 'Choose the month.', 'date.required_if' => 'Choose the date.'];
    }
}
