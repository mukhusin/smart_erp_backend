<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash'],
            'name' => ['required', 'string', 'max:150'],
            'company_branch_id' => ['nullable', 'integer', 'exists:company_branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            /** main, sub, pharmacy or transit. */
            'kind' => ['required', 'in:main,sub,pharmacy,transit'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
