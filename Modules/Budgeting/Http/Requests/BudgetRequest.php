<?php

namespace Modules\Budgeting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'fiscal_year_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer', Rule::exists('fiscal_years', 'id')->where('company_id', $company)],
            /** The annual plan whose activities are budgeted (optional). */
            'annual_plan_id' => ['nullable', 'integer', Rule::exists('annual_plans', 'id')->where('company_id', $company)],
            'title' => ['required', 'string', 'max:200'],
            'submission_due' => ['nullable', 'date'],
        ];
    }
}
