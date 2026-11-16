<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OvertimeRateRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            /** Department, or a section (its department is found). */
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            /** Empty: every position. */
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')->where('company_id', $company)],
            /** hour (paid by the minutes) or shift (one shift per claim). */
            'unit' => ['required', 'in:hour,shift'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
        ];
    }
}
