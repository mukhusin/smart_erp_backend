<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnnualLeavePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            /** Days of annual leave, counted by the annual leave rule. */
            'days' => ['required', 'integer', 'min:1', 'max:366'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
