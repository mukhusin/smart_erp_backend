<?php

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Period number: 1–4 (quarters), 1–12 (months) or 1 (year). */
            'period' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
