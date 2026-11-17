<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpeningBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** accounts, creditors, debtors or breakdowns. Ignored when updating. */
            'kind' => [$this->isMethod('post') ? 'required' : 'nullable', 'in:accounts,creditors,debtors,breakdowns'],
            /** The go-live date, within the first fiscal year. */
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
