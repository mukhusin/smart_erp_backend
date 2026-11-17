<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandleAssetReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Faults: repair, resolved or suspended. Returns: confirmed or declined. */
            'status' => ['required', 'in:repair,resolved,suspended,confirmed,declined'],
            'response' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:200'],
        ];
    }
}
