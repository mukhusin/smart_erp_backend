<?php

namespace Modules\Hr\Http\Requests;

use App\Models\EmployeeNumberFormatSegment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class NumberFormatRequest extends FormRequest
{
    public const SEPARATORS = ['', '/', '-', '.', ':'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['separator' => (string) ($this->input('separator') ?? '')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            /** Placed between segments: empty (none), `/`, `-`, `.` or `:`. */
            'separator' => ['present', 'nullable', Rule::in(self::SEPARATORS)],
            /** Used when no format is chosen (registration, contract types without a format). */
            'is_default' => ['boolean'],
            /** In order. Exactly one `sequence` segment. */
            'segments' => ['required', 'array', 'min:1', 'max:10'],
            'segments.*.type' => ['required', Rule::in(array_keys(EmployeeNumberFormatSegment::TYPES))],
            /** `text`: the text. `department`: code used when the department has no short name. */
            'segments.*.value' => ['nullable', 'string', 'max:50', 'required_if:segments.*.type,text'],
            /** `sequence`: digits (zero padded). `year`: 2 or 4. */
            'segments.*.length' => ['nullable', 'integer', 'min:0', 'max:10'],
            /** `sequence`: first number. */
            'segments.*.start_at' => ['nullable', 'integer', 'min:1', 'max:99999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $segments = collect($this->input('segments', []));
            if ($segments->where('type', 'sequence')->count() !== 1) {
                $validator->errors()->add('segments', 'A format needs exactly one running number segment.');
            }
            foreach ($segments as $i => $segment) {
                if (($segment['type'] ?? null) === 'year' && ! in_array((int) ($segment['length'] ?? 2), [2, 4], true)) {
                    $validator->errors()->add("segments.{$i}.length", 'The year has 2 or 4 digits.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['segments.*.value.required_if' => 'Enter the text.', 'segments.required' => 'Add at least one segment.'];
    }
}
