<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\AccountingSetting;

class AccountingSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Show and report Government Finance Statistics codes on accounts. */
            'use_gfs' => ['required', 'boolean'],
            /** How budgets are split over the year: quarter, month or year. */
            'budget_phasing' => ['required', Rule::in(array_keys(AccountingSetting::PHASING))],
            /** When a request needs more than the budget left: off, warn (extra approval) or block. */
            'budget_control' => ['required', Rule::in(array_keys(AccountingSetting::CONTROL))],
            /** period: money becomes spendable when finance opens each quarter/month; none: the whole approved budget at once. */
            'budget_release' => ['sometimes', Rule::in(array_keys(AccountingSetting::RELEASE))],
        ];
    }
}
