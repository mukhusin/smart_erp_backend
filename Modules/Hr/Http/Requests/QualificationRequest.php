<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

class QualificationRequest extends FormRequest
{
    use NormalizesMultipartBooleans;

    protected function booleanFields(): array
    {
        return ['remove_certificate'];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'education_level' => ['required', O::keys(O::EDUCATION_LEVELS)],
            'institution' => ['nullable', 'string', 'max:150'],
            'program' => ['nullable', 'string', 'max:150'],
            'date_of_start' => ['nullable', 'date'],
            'date_of_end' => ['nullable', 'date', 'after_or_equal:date_of_start'],
            /** Year of completion. */
            'end_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 6)],
            /** PDF, JPG or PNG, up to 5 MB. */
            'certificate' => ['nullable', 'file', ...EmployeeFiles::DOCUMENT_RULES],
            'remove_certificate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['date_of_end.after_or_equal' => 'The end date cannot be before the start date.'];
    }
}
