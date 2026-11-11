<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeOptions as O;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('deleted_at');
        $withAccount = $this->boolean('create_account');

        return [
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('employees')->where('company_id', $companyId)],
            'fname' => ['required', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'lname' => ['required', 'string', 'max:100'],
            'gender' => ['required', O::keys(O::GENDERS)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', O::keys(O::MARITAL_STATUSES)],
            'id_type' => ['nullable', O::keys(O::ID_TYPES)],
            'id_number' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            /** Required when `create_account` is true: the invite is sent here. */
            'email' => array_filter(['nullable', $withAccount ? 'required' : null, 'email', 'max:255', $withAccount ? Rule::unique('users', 'email') : null]),
            'home_address' => ['nullable', 'string', 'max:255'],

            'company_branch_id' => ['required', 'integer', Rule::exists('company_branches', 'id')->where('company_id', $companyId)->where('is_active', true)],
            /** Company-wide or of the employee's branch. */
            'department_id' => ['required', 'integer', $inCompany('departments')->whereNull('parent_id')
                ->where(fn ($q) => $q->whereNull('company_branch_id')->orWhere('company_branch_id', $this->integer('company_branch_id')))],
            /** A section of the chosen department. */
            'section_id' => ['nullable', 'integer', $inCompany('departments')->where('parent_id', $this->integer('department_id'))],
            /** A position of the chosen department (or one not tied to a department). */
            'job_position_id' => ['required', 'integer', $inCompany('job_positions')
                ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $this->integer('department_id')))],
            'emp_title' => ['nullable', 'string', 'max:50'],
            'emp_type' => ['required', O::keys(O::EMPLOYMENT_TYPES)],
            'emp_category' => ['nullable', O::keys(O::CATEGORIES)],
            'employment_date' => ['required', 'date'],
            'employment_status' => ['required', Rule::in(['probation', 'confirmed'])],
            'probation_start' => ['nullable', 'required_if:employment_status,probation', 'date'],
            /** Months. */
            'probation_period' => ['nullable', 'required_if:employment_status,probation', 'integer', 'min:1', 'max:24'],
            'place_of_recruitment' => ['nullable', 'string', 'max:100'],
            'nssf_no' => ['nullable', 'string', 'max:50'],
            'nhif_no' => ['nullable', 'string', 'max:50'],
            'wcf_no' => ['nullable', 'string', 'max:50'],

            /** Also create a user account (role "employee") and email an invite to set the password. */
            'create_account' => ['sometimes', 'boolean'],
            /** Defaults to the email. */
            'username' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('users', 'username')],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'An email is needed to send the account invite.',
            'email.unique' => 'Another user already has this email.',
            'section_id.exists' => 'The section must belong to the chosen department.',
            'department_id.exists' => 'Choose a department of this branch (or one for all branches).',
            'job_position_id.exists' => 'Choose a position of the chosen department.',
            'probation_start.required_if' => 'Enter when probation starts.',
            'probation_period.required_if' => 'Enter the probation period.',
        ];
    }

    /** Employee attributes plus `account` (null = no account). */
    public function employeeData(): array
    {
        $data = collect($this->validated())->except(['create_account', 'username'])->filter(fn ($v) => $v !== null && $v !== '')->all();
        if ($data['employment_status'] !== 'probation') {
            unset($data['probation_start'], $data['probation_period']);
        }
        $data['account'] = $this->boolean('create_account') ? ['username' => $this->validated('username')] : null;

        return $data;
    }
}
