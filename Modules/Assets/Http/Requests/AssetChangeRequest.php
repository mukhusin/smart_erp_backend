<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\AssetChange;

class AssetChangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(AssetChange::KINDS))],
            'date' => ['required', 'date'],
            /** Improvement: amount spent. Revaluation: new fair value. Impairment: tested (recoverable) value. */
            'amount' => ['nullable', 'numeric', 'min:0'],
            /** Life review: months to add (negative to shorten). */
            'months' => ['nullable', 'integer', 'min:-600', 'max:600'],
            /** Improvement: the account the spending was paid from or charged to. */
            'credit_account_id' => ['nullable', 'integer'],
            'valuer' => ['nullable', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
