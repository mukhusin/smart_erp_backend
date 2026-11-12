<?php

namespace Modules\Hr\Http\Requests\Roster;

use Illuminate\Foundation\Http\FormRequest;

class RosterDutiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Changed days. A null roster_code_id clears the day. */
            'duties' => ['required', 'array', 'min:1', 'max:5000'],
            'duties.*.employee_id' => ['required', 'integer'],
            'duties.*.date' => ['required', 'date_format:Y-m-d'],
            'duties.*.roster_code_id' => ['nullable', 'integer'],
            /** Overtime is planned on this day. */
            'duties.*.overtime' => ['boolean'],
        ];
    }
}
