<?php

namespace Modules\Planning\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnualPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'submission_due' => ['nullable', 'date'],
            'strategic_plan_id' => ['nullable', 'integer', Rule::exists('strategic_plans', 'id')->where('company_id', app(CompanyContext::class)->id())],
        ];
    }
}
