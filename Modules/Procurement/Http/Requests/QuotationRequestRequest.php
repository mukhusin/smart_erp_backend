<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotationRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200'], 'closing_date' => ['required', 'date'], 'instructions' => ['nullable', 'string', 'max:3000']];
    }
}
