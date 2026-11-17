<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CountLinesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['counts' => ['required', 'array'], 'counts.*.id' => ['required', 'integer'], 'counts.*.counted' => ['required', 'numeric', 'min:0'], 'counts.*.reason' => ['nullable', 'string', 'max:255']];
    }
}
