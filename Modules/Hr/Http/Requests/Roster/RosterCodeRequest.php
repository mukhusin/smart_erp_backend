<?php

namespace Modules\Hr\Http\Requests\Roster;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RosterCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unique = fn (string $column) => Rule::unique('roster_codes', $column)->where('company_id', app(CompanyContext::class)->id())->ignore($this->route('rosterCode')?->id);

        return [
            /** Short code shown on the roster grid, e.g. M. */
            'code' => ['required', 'string', 'max:10', $unique('code')],
            /** Name, e.g. Morning shift. */
            'duty' => ['required', 'string', 'max:100', $unique('duty')],
            'description' => ['nullable', 'string', 'max:255'],
            /** The employee is expected at work (off, leave and rest codes are not). */
            'is_working' => ['required', 'boolean'],
            'start_time' => ['nullable', 'required_if:is_working,true,1', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:is_working,true,1', 'date_format:H:i'],
            /** Break in minutes, taken off the hours. */
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another duty uses this code.', 'duty.unique' => 'Another duty has this name.',
            'start_time.required_if' => 'Give the start time of a working duty.', 'end_time.required_if' => 'Give the end time of a working duty.',
        ];
    }
}
