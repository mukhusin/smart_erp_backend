<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanProviderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('loan_providers', 'name')->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')->ignore($this->route('provider')?->id)],
            'short_name' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'This provider is already on the list.'];
    }
}
