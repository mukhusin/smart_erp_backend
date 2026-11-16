<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
