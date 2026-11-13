<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignedAllowanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** A monthly category set to "assigned". */
            'allowance_category_id' => ['required', 'integer', Rule::exists('allowance_categories', 'id')->where('company_id', app(CompanyContext::class)->id())],
            /** Amount a month. */
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'start_date' => ['required', 'date'],
            /** Empty: until stopped. */
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
