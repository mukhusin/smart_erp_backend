<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

class DependantRequest extends FormRequest
{
    use NormalizesMultipartBooleans;

    protected function booleanFields(): array
    {
        return ['is_next_of_kin', 'remove_birth_certificate'];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $relationship = ['required', O::keys(O::RELATIONSHIPS)];
        if (! in_array($this->input('relationship'), O::MULTIPLE_RELATIONSHIPS, true)) {
            $relationship[] = Rule::unique('employee_dependants')
                ->where('employee_id', $this->route('employee')->id)
                ->ignore($this->route('dependant')?->id);
        }

        return [
            'fullname' => ['required', 'string', 'min:3', 'max:150'],
            'gender' => ['required', O::keys(O::GENDERS)],
            /** Only `child` can be added more than once. */
            'relationship' => $relationship,
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\s\-\+\(\)]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:250'],
            /** Only one dependant is the next of kin; setting it moves it from the others. */
            'is_next_of_kin' => ['sometimes', 'boolean'],
            /** PDF, JPG or PNG, up to 5 MB. */
            'birth_certificate' => ['nullable', 'file', ...EmployeeFiles::DOCUMENT_RULES],
            'remove_birth_certificate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'relationship.unique' => 'This employee already has a dependant with this relationship.',
            'phone.regex' => 'Enter a valid phone number.',
        ];
    }
}
