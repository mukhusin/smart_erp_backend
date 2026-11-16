<?php

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** transfer (between items) or supplementary (new money). */
            'kind' => ['required', 'in:transfer,supplementary'],
            'from_item_id' => ['required_if:kind,transfer', 'nullable', 'integer'],
            'to_item_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
