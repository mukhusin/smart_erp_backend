<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'users' => ['present', 'array'],
            'users.*.user_id' => ['required', 'integer', 'exists:users,id', 'distinct'],
            /** officer (prepares documents) or manager (approves). */
            'users.*.role' => ['required', 'in:officer,manager'],
        ];
    }
}
