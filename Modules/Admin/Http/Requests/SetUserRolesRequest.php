<?php

namespace Modules\Admin\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The full list of the user's roles (names). A user can have several. */
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('company_id', app(CompanyContext::class)->id())],
            /** Why the access changed (kept in the history; will be shown to approvers). */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
