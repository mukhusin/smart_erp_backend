<?php

namespace Modules\Budgeting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetItemRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'task_activity_id' => ['nullable', 'integer'],
            'sub_activity_id' => ['nullable', 'integer'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $company)],
            'fund_source_id' => ['nullable', 'integer', Rule::exists('fund_sources', 'id')->where('company_id', $company)],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:30'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            /** Times per year (e.g. 12 for monthly). */
            'frequency' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            /** Amount per period (4 quarters, 12 months or 1 year); empty to split evenly. */
            'periods' => ['nullable', 'array', 'max:12'],
            'periods.*' => ['numeric', 'min:0'],
        ];
    }
}
