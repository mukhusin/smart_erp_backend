<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemLevelsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'levels' => ['present', 'array'],
            'levels.*.store_id' => ['required', 'integer', 'exists:stores,id', 'distinct'],
            'levels.*.min_level' => ['nullable', 'numeric', 'min:0'], 'levels.*.max_level' => ['nullable', 'numeric', 'min:0'], 'levels.*.reorder_level' => ['nullable', 'numeric', 'min:0'],
            'levels.*.store_bin_id' => ['nullable', 'integer'],
        ];
    }
}
