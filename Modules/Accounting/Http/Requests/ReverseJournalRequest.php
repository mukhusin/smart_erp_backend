<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
