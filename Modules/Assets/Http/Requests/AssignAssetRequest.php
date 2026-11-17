<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\Asset;

class AssignAssetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:200'],
            'date' => ['nullable', 'date'],
            'condition' => ['nullable', Rule::in(array_keys(Asset::CONDITIONS))],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
