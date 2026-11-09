<?php

namespace Modules\Admin\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModuleLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();

        return [
            'module_slug' => ['required', 'string', Rule::exists('erp_modules', 'slug')->where('is_core', false)],
            /** Lock for a whole branch... */
            'company_branch_id' => ['nullable', 'required_without:user_id', 'prohibits:user_id', 'integer', Rule::exists('company_branches', 'id')->where('company_id', $companyId)],
            /** ...or for one user. */
            'user_id' => ['nullable', 'required_without:company_branch_id', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
