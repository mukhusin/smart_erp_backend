<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:internal,external'],
            'permanent' => ['nullable', 'boolean'],
            'date' => ['required', 'date'],
            'return_date' => ['nullable', 'date', 'after_or_equal:date'],
            'to_branch_id' => ['nullable', 'integer'],
            'to_department_id' => ['nullable', 'integer'],
            'to_employee_id' => ['nullable', 'integer'],
            'to_location' => ['nullable', 'string', 'max:200'],
            'to_organisation' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
