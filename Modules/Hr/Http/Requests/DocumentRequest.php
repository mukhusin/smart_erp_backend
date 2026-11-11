<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file_type' => ['required', O::keys(O::DOCUMENT_TYPES)],
            'description' => ['nullable', 'string', 'max:1000'],
            /** PDF, JPG or PNG, up to 5 MB. Required when uploading; optional when editing (keeps the file). */
            'file' => [$this->route('document') ? 'nullable' : 'required', 'file', ...EmployeeFiles::DOCUMENT_RULES],
        ];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose the file to upload.', 'file_type.required' => 'Choose the document type.'];
    }
}
