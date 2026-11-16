<?php

namespace Modules\Planning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Planning\Models\StrategicPlan;

class StrategicPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'start_year' => ['required', 'integer', 'between:2000,2100'],
            'end_year' => ['required', 'integer', 'gte:start_year', 'lte:'.((int) $this->input('start_year') + 15)],
            'vision' => ['nullable', 'string', 'max:2000'],
            'mission' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(array_keys(StrategicPlan::STATUS_LABELS))],
        ];
    }
}
