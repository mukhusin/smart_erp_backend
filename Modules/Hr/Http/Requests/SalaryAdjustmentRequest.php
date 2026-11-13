<?php

namespace Modules\Hr\Http\Requests;

use App\Models\SalaryAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_salary' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            /** promotion, demotion, performance, market, cost_of_living, correction or other. */
            'reason' => ['required', Rule::in(array_keys(SalaryAdjustment::REASONS))],
            /** From this date the contract gets the new salary (default today; a future date is applied on that day). */
            'effective_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
