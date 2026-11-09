<?php

namespace Modules\Admin\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Support\PermissionCatalog;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9 _-]*$/i',
                Rule::unique('roles', 'name')->where('company_id', $companyId)->where('guard_name', 'web')->ignore($this->route('role'))],
            'description' => ['nullable', 'string', 'max:500'],
            /** Permission names from GET /admin/permissions. */
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::names())],
        ];
    }
}
