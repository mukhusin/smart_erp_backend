<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Models\ProcurementMethod;

class PlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'lines' => ['present', 'array', 'max:2000'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.department_id' => ['required', 'integer', 'exists:departments,id'],
            'lines.*.budget_item_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.category' => ['required', 'in:'.implode(',', array_keys(ProcurementMethod::CATEGORIES))],
            'lines.*.procurement_method_id' => ['nullable', 'integer', 'exists:procurement_methods,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
            /** Quarter of the year the purchase should start, 1 to 4. */
            'lines.*.quarter' => ['required', 'integer', 'between:1,4'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
