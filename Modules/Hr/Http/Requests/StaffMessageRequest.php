<?php

namespace Modules\Hr\Http\Requests;

use App\Models\Messaging;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeOptions as O;

class StaffMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);
        $type = $this->input('type');

        return [
            /** SMS, Email or Notice (in-app). */
            'type' => ['required', Rule::in([Messaging::TYPE_SMS, Messaging::TYPE_EMAIL, Messaging::TYPE_NOTICE])],
            /** Email subject or notice title. */
            'subject' => [$type === Messaging::TYPE_SMS ? 'nullable' : 'required', 'string', 'max:150'],
            /** Placeholders: {first_name}, {name}, {employee_no}. SMS is cut at the gateway limit. */
            'message' => ['required', 'string', $type === Messaging::TYPE_SMS ? 'max:'.config('sms.max_length', 120) : 'max:5000'],
            /** individual, department, section, branch or all */
            'recipient_type' => ['required', Rule::in([Messaging::RECIPIENT_INDIVIDUAL, Messaging::RECIPIENT_DEPARTMENT, Messaging::RECIPIENT_SECTION, Messaging::RECIPIENT_BRANCH, Messaging::RECIPIENT_ALL])],
            'employee_ids' => ['required_if:recipient_type,individual', 'array', 'max:2000'],
            'employee_ids.*' => ['integer', $inCompany('employees')],
            'department_id' => ['nullable', 'required_if:recipient_type,department', 'integer', $inCompany('departments')],
            'section_id' => ['nullable', 'required_if:recipient_type,section', 'integer', $inCompany('departments')],
            'branch_id' => ['nullable', 'required_if:recipient_type,branch', 'integer', $inCompany('company_branches')],
            /** Only this employee type. */
            'emp_type' => ['nullable', O::keys(O::EMPLOYMENT_TYPES)],
            /** Empty = send now. */
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return ['scheduled_at.after' => 'Choose a time in the future, or leave it empty to send now.'];
    }
}
