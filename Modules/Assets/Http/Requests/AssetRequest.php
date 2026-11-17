<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\Asset;

class AssetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'item_category_id' => ['required', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'asset_class' => ['nullable', Rule::in(array_keys(Asset::CLASSES))],
            /** Type-specific fields, e.g. registration and chassis number for vehicles, plot and title deed for land. */
            'details' => ['nullable', 'array'],
            'details.*' => ['nullable', 'string', 'max:200'],
            'acquisition_type' => ['required', Rule::in(array_keys(Asset::ACQUISITION))],
            'acquisition_date' => ['required', 'date'],
            'in_use_date' => ['nullable', 'date'],
            /** Cost of one unit. */
            'cost' => ['required', 'numeric', 'min:0.01'],
            /** Opening balances only: depreciation already charged in the old books. */
            'opening_depreciation' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'fund_source_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:100'],
            'company_branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:200'],
            'condition' => ['nullable', Rule::in(array_keys(Asset::CONDITIONS))],
            /** New assets only: identical units each get their own record and number. */
            'quantity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'serials' => ['nullable', 'array'],
            'serials.*' => ['nullable', 'string', 'max:100'],
        ];
    }
}
