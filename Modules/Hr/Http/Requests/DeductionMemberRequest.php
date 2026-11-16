<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Hr\Support\DeductionSchemes;

class DeductionMemberRequest extends FormRequest
{
    private function scheme(): array
    {
        return DeductionSchemes::SCHEMES[(string) $this->route('scheme')]
            ?? ['label' => 'Deduction', 'type' => 'amount', 'total' => null, 'number' => 'optional', 'number_label' => 'Member no.'];
    }

    public function rules(): array
    {
        $s = $this->scheme();

        return [
            'contribution_no' => [$s['number'] === 'required' ? 'required' : 'nullable', 'string', 'max:50'],
            'value' => ['required', 'numeric', 'min:0', $s['type'] === 'percent' ? 'max:'.($s['total'] ?? 100) : 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $s = $this->scheme();

        return [
            'contribution_no.required' => "Enter the {$s['label']} ".strtolower((string) $s['number_label']).'.',
            'value.max' => $s['total'] ? "The employee's share cannot be more than {$s['total']}%." : 'The value is too high.',
        ];
    }
}
