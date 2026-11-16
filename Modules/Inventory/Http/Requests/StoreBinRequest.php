<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBinRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:100'], 'parent_id' => ['nullable', 'integer']];
    }
}
