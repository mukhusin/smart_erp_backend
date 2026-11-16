<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'delivery_place' => ['nullable', 'string', 'max:200'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
