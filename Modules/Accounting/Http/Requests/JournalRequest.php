<?php

namespace Modules\Accounting\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalRequest extends FormRequest
{
    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $company)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)],
            'lines.*.fund_source_id' => ['nullable', 'integer', Rule::exists('fund_sources', 'id')->where('company_id', $company)],
            'lines.*.task_activity_id' => ['nullable', 'integer', Rule::exists('task_activities', 'id')->where('company_id', $company)],
            /** Budget item the line spends against (when Budgeting is used). */
            'lines.*.budget_item_id' => ['nullable', 'integer', Rule::exists('budget_items', 'id')->where('company_id', $company)],
        ];
    }
}
