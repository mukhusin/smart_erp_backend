<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'awards' => ['required', 'array', 'min:1'],
            'awards.*.purchase_requisition_line_id' => ['required', 'integer'],
            'awards.*.quotation_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
