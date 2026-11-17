<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return ['status' => ['required', 'in:active,stored,suspended']];
    }
}
