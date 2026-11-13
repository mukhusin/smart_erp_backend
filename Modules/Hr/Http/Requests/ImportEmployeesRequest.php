<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('commit')) {
            $this->merge(['commit' => $this->boolean('commit')]);
        }
    }

    public function rules(): array
    {
        return [
            /** CSV in the template layout, max 5 MB. */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            /** False = check only. */
            'commit' => ['sometimes', 'boolean'],
        ];
    }
}
