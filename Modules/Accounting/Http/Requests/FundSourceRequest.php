<?php

namespace Modules\Accounting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\FundSource;

class FundSourceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('fund_sources', 'code')->where('company_id', app(CompanyContext::class)->id())->ignore($this->route('fundSource')?->id)],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(FundSource::KINDS))],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.unique' => 'Another fund source has this code.'];
    }
}
