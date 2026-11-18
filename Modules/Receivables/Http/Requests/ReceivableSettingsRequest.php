<?php

namespace Modules\Receivables\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivableSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'receivable_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'default_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            /** off, warn (flag invoices over limit for the approver) or block (they cannot be issued). */
            'credit_check' => ['sometimes', 'in:off,warn,block'],
            /** Also stop new invoices when an older one is overdue more than these days; empty = no check. */
            'credit_overdue_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            /** Insurance / third-party billing: claim batches to payers. */
            'claims_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
