<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $account = ['nullable', 'integer', 'exists:accounts,id'];

        return [
            'code' => ['required', 'string', 'max:15', 'alpha_dash'],
            'name' => ['required', 'string', 'max:100'],
            /** store, asset, intangible, wip or service. */
            'type' => ['required', 'in:store,asset,intangible,wip,service'],
            'inventory_account_id' => $account, 'expense_account_id' => $account, 'revenue_account_id' => $account, 'asset_account_id' => $account,
            'depreciation_account_id' => $account, 'accumulated_account_id' => $account,
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'residual_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'capitalisation_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
