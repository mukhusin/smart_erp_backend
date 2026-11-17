<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Models\AssetDisposal;

class AssetDisposalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(array_keys(AssetDisposal::METHODS))],
            'date' => ['required', 'date'],
            'proceeds' => ['nullable', 'numeric', 'min:0'],
            'cash_bank_account_id' => ['nullable', 'integer'],
            'buyer' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:1000'],
            /** Minutes of the disposal board or committee. */
            'minutes' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ];
    }
}
