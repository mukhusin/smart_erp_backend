<?php

namespace Modules\Hr\Http\Requests;

use App\Models\LeaveType;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeOptions;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unique = fn (string $column) => Rule::unique('leave_types', $column)->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')->ignore($this->route('leaveType')?->id);

        return [
            'name' => ['required', 'string', 'max:100', $unique('name')],
            'code' => ['required', 'string', 'max:10', $unique('code')],
            /** annual, sick, maternity, paternity, compassionate, study, unpaid, other. Annual leave is only for employees whose contract gives it. */
            'kind' => ['required', Rule::in(array_keys(LeaveType::KINDS))],
            /** all, male or female. */
            'gender' => ['required', 'in:all,male,female'],
            'description' => ['nullable', 'string', 'max:500'],
            'with_pay' => ['required', 'boolean'],
            /** Days per year (limit_per = year) or per request (limit_per = request). */
            'max_days' => ['required', 'integer', 'min:1', 'max:366'],
            /** working, calendar_no_holidays or calendar. */
            'day_count' => ['required', Rule::in(array_keys(LeaveType::DAY_COUNTS))],
            'limit_per' => ['required', 'in:year,request'],
            /** Days left at the end of a year that move to the next year (yearly types). Empty: none. */
            'carry_over_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            /** How many days before the start the request must be made. */
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'requires_document' => ['boolean'],
            /** Employee types that can take it; empty: all. */
            'employee_types' => ['nullable', 'array'],
            'employee_types.*' => ['string', Rule::in(array_keys(EmployeeOptions::EMPLOYMENT_TYPES))],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Another leave type has this name.', 'code.unique' => 'Another leave type has this code.'];
    }
}
