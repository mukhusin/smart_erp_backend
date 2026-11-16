<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'item_category_id' => ['required', 'integer', 'exists:item_categories,id'],
            /** internal, sale or both. */
            'use' => ['required', 'in:internal,sale,both'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'track_batch' => ['nullable', 'boolean'], 'track_expiry' => ['nullable', 'boolean'], 'track_serial' => ['nullable', 'boolean'],
            'min_level' => ['nullable', 'numeric', 'min:0'], 'max_level' => ['nullable', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'units' => ['nullable', 'array', 'max:10'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id', 'distinct'],
            'units.*.factor' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
