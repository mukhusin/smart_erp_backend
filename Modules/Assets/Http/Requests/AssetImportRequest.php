<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetImportRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']];
    }
}
