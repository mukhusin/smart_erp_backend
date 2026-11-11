<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        $employee = $this->route('employee');
        $rules = parent::rules();
        unset($rules['create_account'], $rules['username']);

        $userId = $employee?->user_id;

        $rules['employee_no'] = ['required', 'string', 'max:50', Rule::unique('employees')->where('company_id', $employee?->company_id)->ignore($employee?->id)];
        /** Required when the employee has a user account; the account email changes with it. */
        $rules['email'] = array_filter(['nullable', $userId ? 'required' : null, 'email', 'max:255', $userId ? Rule::unique('users', 'email')->ignore($userId) : null]);
        $rules['employment_status'] = ['required', Rule::in($employee?->employment_status === 'terminated' ? ['probation', 'confirmed', 'terminated'] : ['probation', 'confirmed'])];

        return $rules;
    }

    public function messages(): array
    {
        return ['email.required' => 'This employee has a user account, so the email is needed.'] + parent::messages();
    }

    /** All employee attributes; empty fields clear the stored value. */
    public function employeeData(): array
    {
        $data = collect($this->validated())->map(fn ($v) => $v === '' ? null : $v)->all();
        if ($data['employment_status'] !== 'probation') {
            $data['probation_start'] = null;
            $data['probation_period'] = null;
        }

        return $data;
    }
}
