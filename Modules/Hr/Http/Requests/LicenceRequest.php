<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeFiles;

class LicenceRequest extends FormRequest
{
    use NormalizesMultipartBooleans;

    protected function booleanFields(): array
    {
        return ['remove_file'];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_licence_id' => ['required', 'integer', Rule::exists('professional_licences', 'id')
                ->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')],
            'licence_no' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            /** PDF, JPG or PNG, up to 5 MB. */
            'file' => ['nullable', 'file', ...EmployeeFiles::DOCUMENT_RULES],
            'remove_file' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['professional_licence_id.required' => 'Choose the licence.', 'end_date.after' => 'The expiry date must be after the issue date.'];
    }
}
