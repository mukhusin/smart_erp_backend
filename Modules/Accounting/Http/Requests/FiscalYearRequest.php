<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiscalYearRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** First day of the year; twelve monthly periods are created. */
            'start_date' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:50'],
        ];
    }
}
