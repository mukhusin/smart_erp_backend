<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
