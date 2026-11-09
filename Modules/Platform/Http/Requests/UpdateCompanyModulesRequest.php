<?php

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The full list of enabled modules. Modules not listed are disabled. */
            'modules' => ['present', 'array'],
            'modules.*.slug' => ['required', 'string', 'distinct', Rule::exists('erp_modules', 'slug')->where('is_active', true)],
            'modules.*.expires_at' => ['nullable', 'date'],
        ];
    }
}
