<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nature_of_contract_id' => ['required', 'integer', Rule::exists('nature_of_contracts', 'id')
                ->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)->whereNull('deleted_at')],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'start_date' => ['required', 'date'],
            /** Months. The end date is start + period - 1 day. */
            'period' => ['required', 'integer', 'min:1', 'max:120'],
            /** Whether the employee gets annual leave. */
            'has_annual_leave' => ['sometimes', 'boolean'],
            /** When the change takes effect (adjustments only). Defaults to today. */
            'date_of_change' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['nature_of_contract_id.required' => 'Choose the contract type.'];
    }
}
