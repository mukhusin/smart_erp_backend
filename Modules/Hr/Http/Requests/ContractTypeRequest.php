<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeOptions as O;

class ContractTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20'],
            /** The employee type a contract of this kind gives. */
            'employment_type' => ['required', O::keys(O::EMPLOYMENT_TYPES)],
            /** Number format for employees on this contract type (null = the default format). */
            'employee_number_format_id' => ['nullable', 'integer', Rule::exists('employee_number_formats', 'id')->where('company_id', app(CompanyContext::class)->id())],
            'has_annual_leave' => ['boolean'],
            'has_payroll' => ['boolean'],
            'has_overtime' => ['boolean'],
            'has_allowance' => ['boolean'],
            'has_emergency_fund' => ['boolean'],
            'is_active' => ['boolean'],
            'contract_description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
