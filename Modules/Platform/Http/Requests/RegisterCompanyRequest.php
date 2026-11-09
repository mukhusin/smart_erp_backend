<?php

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: platform admin only
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'company.short_name' => ['nullable', 'string', 'max:50'],
            'company.registration_number' => ['nullable', 'string', 'max:100', 'unique:companies,registration_number'],
            'company.tin' => ['nullable', 'string', 'max:50'],
            'company.phone' => ['nullable', 'string', 'max:50'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.website' => ['nullable', 'url', 'max:255'],
            'company.address' => ['nullable', 'string', 'max:500'],
            'company.city' => ['nullable', 'string', 'max:100'],
            'company.country' => ['nullable', 'string', 'max:100'],
            'company.currency' => ['nullable', 'string', 'size:3'],
            /** Month and day the fiscal year starts, e.g. "07-01". */
            'company.fiscal_year_start' => ['nullable', 'string', 'max:10'],

            /** At least one branch. The one marked is_headquarters (or the first) is the head office. */
            'branches' => ['required', 'array', 'min:1'],
            'branches.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'branches.*.code' => ['nullable', 'string', 'max:20', 'distinct'],
            'branches.*.phone' => ['nullable', 'string', 'max:50'],
            'branches.*.email' => ['nullable', 'email', 'max:255'],
            'branches.*.address' => ['nullable', 'string', 'max:500'],
            'branches.*.city' => ['nullable', 'string', 'max:100'],
            'branches.*.is_headquarters' => ['sometimes', 'boolean'],

            /** Modules the company may use. Core modules are always available. */
            'modules' => ['sometimes', 'array'],
            'modules.*.slug' => ['required', 'string', 'distinct', Rule::exists('erp_modules', 'slug')->where('is_active', true)],
            'modules.*.expires_at' => ['nullable', 'date', 'after:today'],

            /** The company's first HR admin. Gets an employee record, a user account and an invite email. */
            'admin' => ['required', 'array'],
            'admin.fname' => ['required', 'string', 'max:100'],
            'admin.mname' => ['nullable', 'string', 'max:100'],
            'admin.lname' => ['required', 'string', 'max:100'],
            'admin.gender' => ['required', Rule::in(['Male', 'Female'])],
            'admin.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin.phone_number' => ['nullable', 'string', 'max:50'],
            /** Defaults to the email address. */
            'admin.username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'admin.employee_no' => ['nullable', 'string', 'max:50'],
            'admin.position' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $hq = collect($this->input('branches', []))->where('is_headquarters', true)->count();
            if ($hq > 1) {
                $validator->errors()->add('branches', 'Only one branch can be the head office.');
            }
            if (! $this->input('admin.username') && \App\Models\User::where('username', strtolower((string) $this->input('admin.email')))->exists()) {
                $validator->errors()->add('admin.username', 'This email is already used as a username. Enter a username.');
            }
        }];
    }
}
