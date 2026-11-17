<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpeningRowsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Each row is checked on its own; good rows are kept and the result of every row is returned. Give ids or codes/names. */
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.reference' => ['nullable', 'string', 'max:60'],
            'rows.*.account_id' => ['nullable', 'integer'],
            'rows.*.account_code' => ['nullable', 'string', 'max:30'],
            /** Account balances only. */
            'rows.*.debit' => ['nullable', 'numeric'],
            'rows.*.credit' => ['nullable', 'numeric'],
            /** Creditors, debtors and breakdowns. */
            'rows.*.amount' => ['nullable', 'numeric'],
            'rows.*.department_id' => ['nullable', 'integer'],
            'rows.*.department' => ['nullable', 'string', 'max:150'],
            'rows.*.fund_source_id' => ['nullable', 'integer'],
            'rows.*.fund_source' => ['nullable', 'string', 'max:20'],
            'rows.*.task_activity_id' => ['nullable', 'integer'],
            'rows.*.supplier_id' => ['nullable', 'integer'],
            /** Supplier name; created when not found by TIN or name. */
            'rows.*.supplier' => ['nullable', 'string', 'max:200'],
            'rows.*.supplier_tin' => ['nullable', 'string', 'max:30'],
            'rows.*.customer_id' => ['nullable', 'integer'],
            'rows.*.customer' => ['nullable', 'string', 'max:200'],
            'rows.*.customer_tin' => ['nullable', 'string', 'max:30'],
            /** Breakdowns: client or other party the amount belongs to. */
            'rows.*.party' => ['nullable', 'string', 'max:200'],
            'rows.*.document_date' => ['nullable', 'string', 'max:20'],
            'rows.*.due_date' => ['nullable', 'string', 'max:20'],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
