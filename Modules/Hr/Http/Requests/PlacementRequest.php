<?php

namespace Modules\Hr\Http\Requests;

use App\Models\EmployeePlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** `internship` (employee type internship) or `residency` (employee type resident). */
            'type' => ['required', Rule::in(array_keys(EmployeePlacement::TYPES))],
            'start_date' => ['required', 'date'],
            /** Months. End date = start + period - 1 day. */
            'period' => ['required', 'integer', 'min:1', 'max:60'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
