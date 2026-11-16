<?php

namespace Modules\Accounting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashBankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:bank,cash,mobile'],
            /** The ledger account (an asset account) this bank or cash account posts to. */
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $company)->where('type', 'asset')->where('is_postable', true)],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
