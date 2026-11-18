<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\TaxCode;

class TaxCodeRequest extends FormRequest
{
    public function rules(): array
    {
        $code = $this->route('code');

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('tax_codes', 'code')->where('company_id', $this->user()?->company_id)->ignore($code instanceof TaxCode ? $code->id : null)],
            'name' => ['required', 'string', 'max:100'],
            /** standard, zero or exempt; only standard codes carry a rate. */
            'kind' => ['required', Rule::in(array_keys(TaxCode::KINDS))],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
