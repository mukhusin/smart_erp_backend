<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Support\PermissionCatalog;

class SetUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Permissions given directly to the user, on top of their roles. Prefer roles. */
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::names())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
