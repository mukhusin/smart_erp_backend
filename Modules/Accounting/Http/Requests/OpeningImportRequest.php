<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpeningImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** CSV in the template layout of the batch kind. */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
