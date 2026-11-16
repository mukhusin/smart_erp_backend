<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnitRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:15'], 'name' => ['required', 'string', 'max:50'], 'is_active' => ['nullable', 'boolean']];
    }
}
