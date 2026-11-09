<?php

namespace Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Required when deactivating. */
            'reason' => [$this->routeIs('admin.users.deactivate') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
