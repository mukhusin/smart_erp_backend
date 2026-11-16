<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportAccountsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            /** Check only: report what would be imported without saving. */
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
