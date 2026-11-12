<?php

namespace Modules\Hr\Http\Requests\Roster;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();
        $creating = ! $this->route('roster');

        return [
            /** Department, or a section (its department is found). */
            'department_id' => [Rule::requiredIf($creating), 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            /** Left empty: the department or section and the period, e.g. "Ward A · December 2026". */
            'title' => [$creating ? 'nullable' : 'required', 'string', 'max:150'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            /** Fill the new roster by repeating the duties of this roster. */
            'repeat_from_id' => ['nullable', 'integer', Rule::exists('rosters', 'id')->where('company_id', $company)->where('type', 'duty')],
        ];
    }
}
