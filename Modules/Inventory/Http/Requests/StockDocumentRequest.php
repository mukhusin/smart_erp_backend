<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** issue, transfer, receipt or count. */
            'kind' => ['required', 'in:issue,transfer,receipt,count'],
            /** receipts: opening, donation, found or return. */
            'reason' => ['nullable', 'in:opening,donation,found,return'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_requisition_id' => ['nullable', 'integer', 'exists:store_requisitions,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'task_activity_id' => ['nullable', 'integer', 'exists:task_activities,id'],
            'received_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'item_category_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required_unless:kind,count', 'array', 'max:500'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.store_requisition_line_id' => ['nullable', 'integer'],
            'lines.*.stock_layer_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
