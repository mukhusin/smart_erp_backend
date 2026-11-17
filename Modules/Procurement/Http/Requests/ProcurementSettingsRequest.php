<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcurementSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'clearing_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'direct_limit' => ['required', 'numeric', 'min:0'],
            'min_quotes' => ['required', 'integer', 'min:1', 'max:10'],
            'match_tolerance_percent' => ['required', 'numeric', 'min:0', 'max:20'],
            /** Liability holding retention money until it is released. */
            'retention_account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            /** Income (or cost recovery) account for liquidated damages deducted from suppliers. */
            'damages_account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            /** Asset account for advances paid to suppliers, recovered from later certificates. */
            'advance_account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            /** Total price increases allowed by variations, as a percentage of the contract value. */
            'variation_limit_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'expiry_warning_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'award_acceptance_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
