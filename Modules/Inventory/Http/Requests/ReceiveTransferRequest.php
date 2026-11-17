<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return ['lines' => ['required', 'array'], 'lines.*.id' => ['required', 'integer'], 'lines.*.received' => ['required', 'numeric', 'min:0'], 'lines.*.reason' => ['nullable', 'string', 'max:255']];
    }
}
