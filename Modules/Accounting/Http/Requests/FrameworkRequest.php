<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Support\FrameworkCatalog;

class FrameworkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** ifrs, ifrs_sme, us_gaap, ipsas or local. */
            'framework' => ['required', 'in:'.implode(',', array_keys(FrameworkCatalog::PROFILES))],
            'framework_name' => ['nullable', 'string', 'max:100'],
            'policies' => ['nullable', 'array'],
            'policies.*' => ['string', 'max:30'],
            'effective_from' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
