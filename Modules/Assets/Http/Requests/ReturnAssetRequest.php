<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\Asset;

class ReturnAssetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'condition' => ['required', Rule::in(array_keys(Asset::CONDITIONS))],
            'date' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
            /** True when an employee hands it back but the department keeps it. */
            'keep_department' => ['nullable', 'boolean'],
        ];
    }
}
