<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtherDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            /** Amount taken every month. */
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            /** First payroll month (Y-m). */
            'start_month' => ['required', 'date_format:Y-m'],
            /** Number of months; empty: until stopped. */
            'months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
