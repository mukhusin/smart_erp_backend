<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepreciationRunRequest extends FormRequest
{
    public function rules(): array
    {
        return ['period_end' => ['required', 'date']];
    }
}
