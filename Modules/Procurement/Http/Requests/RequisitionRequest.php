<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequisitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:200'],
            'purpose' => ['nullable', 'string', 'max:3000'],
            /** normal or special (emergency / outside the plan, needs a reason). */
            'kind' => ['required', 'in:normal,special'],
            'special_reason' => ['nullable', 'string', 'max:2000'],
            'needed_by' => ['nullable', 'date'],
            'delivery_place' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.specification' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
        ];
    }
}
