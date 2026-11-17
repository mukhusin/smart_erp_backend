<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequisitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'task_activity_id' => ['nullable', 'integer', 'exists:task_activities,id'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'needed_by' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
