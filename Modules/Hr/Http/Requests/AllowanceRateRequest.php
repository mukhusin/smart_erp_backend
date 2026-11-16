<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllowanceRateRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'category_id' => ['required', 'integer', Rule::exists('allowance_categories', 'id')->where('company_id', $company)],
            /** Department, or a section (its department is found). */
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            /** Empty: every position in the department or section. */
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')->where('company_id', $company)],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
        ];
    }
}
