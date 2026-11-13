<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'loan_provider_id' => ['required', 'integer', Rule::exists('loan_providers', 'id')->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            /** Taken from salary each payroll. */
            'instalment' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            /** Repayments start on payrolls ending on or after this date. */
            'date_of_loan' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
