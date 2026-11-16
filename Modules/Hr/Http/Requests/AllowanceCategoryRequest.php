<?php

namespace Modules\Hr\Http\Requests;

use App\Models\AllowanceCategory;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllowanceCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();
        $id = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('allowance_categories', 'category')->where('company_id', $company)->ignore($id)],
            'code' => ['required', 'string', 'max:20', Rule::unique('allowance_categories', 'code')->where('company_id', $company)->ignore($id)],
            /** month, day (working day), night (night shift), holiday (public holiday worked) or case (counted items). */
            'unit' => ['required', Rule::in(array_keys(AllowanceCategory::UNITS))],
            /** auto: worked out from the roster and working hours; claim: entered with a count and approved; assigned (monthly only): a fixed amount set per employee; activity (per case only): a daily count times the rate for everyone the rate covers. */
            'generation' => ['required', 'in:auto,claim,assigned,activity'],
            /** Activity allowances: manual (count entered by hand) or rest (fetched from another system). */
            'activity_source' => ['nullable', 'in:manual,rest'],
            'activity_config' => ['nullable', 'array'],
            /** Address of the other system; parameter values may use {date} (YYYY-MM-DD). */
            'activity_config.url' => ['required_if:activity_source,rest', 'nullable', 'url', 'max:500'],
            'activity_config.params' => ['nullable', 'array'],
            'activity_config.params.*' => ['nullable', 'string', 'max:200'],
            /** Bearer token; leave empty to keep the saved one. */
            'activity_config.token' => ['nullable', 'string', 'max:500'],
            'activity_config.clear_token' => ['boolean'],
            /** Where the number is in the answer, e.g. data.count. Or use list_path + count_field. */
            'activity_config.count_path' => ['nullable', 'string', 'max:200'],
            'activity_config.list_path' => ['nullable', 'string', 'max:200'],
            'activity_config.count_field' => ['nullable', 'string', 'max:100'],
            'activity_config.id_field' => ['nullable', 'string', 'max:100'],
            /** Only items with these ids are counted (empty: all). */
            'activity_config.ids' => ['nullable', 'array', 'max:5000'],
            'activity_config.ids.*' => ['string', 'max:50'],
            /** Not paid on absent days (when attendance is used). */
            'other_absence' => ['boolean'],
            'taxable' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Another category has this name.', 'code.unique' => 'Another category has this code.'];
    }
}
