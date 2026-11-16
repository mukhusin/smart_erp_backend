<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            /** direct (under the limit), single (single source) or framework (call-off). */
            'method' => ['required', 'in:direct,single,framework'],
            'method_reason' => ['nullable', 'string', 'max:2000'],
            'order_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_requisition_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
