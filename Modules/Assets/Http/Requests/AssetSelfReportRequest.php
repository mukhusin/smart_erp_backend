<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\Asset;

class AssetSelfReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** fault (damage or not working) or return (handing it back). */
            'type' => ['required', 'in:fault,return'],
            'description' => ['nullable', 'string', 'max:2000'],
            'condition' => ['nullable', Rule::in(array_keys(Asset::CONDITIONS))],
            'photo' => ['nullable', 'image', 'max:8192'],
        ];
    }
}
