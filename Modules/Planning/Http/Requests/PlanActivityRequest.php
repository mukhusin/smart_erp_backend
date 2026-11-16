<?php

namespace Modules\Planning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlanActivityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'output' => ['nullable', 'string', 'max:500'],
            'target' => ['nullable', 'string', 'max:500'],
            'kpi' => ['nullable', 'string', 'max:500'],
            /** Quarters the activity runs, e.g. 1,2,4. */
            'quarters' => ['nullable', 'string', 'regex:/^[1-4](,[1-4]){0,3}$/'],
            'strategic_objective_id' => ['nullable', 'integer'],
        ];
    }
}
