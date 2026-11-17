<?php

namespace Modules\Accounting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\Account;

class AccountRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('accounts', 'code')->where('company_id', $company)->ignore($this->route('account')?->id)],
            /** Government Finance Statistics code; only used when the company uses GFS codes. */
            'gfs_code' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $company)],
            /** False for heading accounts that only group others. */
            'is_postable' => ['sometimes', 'boolean'],
            /** Budgets can be set on it (usually expense and revenue accounts). */
            'is_budgetable' => ['sometimes', 'boolean'],
            /** Cash flow statement section: operating, investing (fixed assets, investments) or financing (loans, capital). Empty = by type. */
            'cash_flow' => ['nullable', Rule::in(array_keys(Account::CASH_FLOW))],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.unique' => 'Another account has this code.'];
    }
}
