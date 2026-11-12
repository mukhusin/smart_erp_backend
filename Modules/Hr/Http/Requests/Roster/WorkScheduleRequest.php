<?php

namespace Modules\Hr\Http\Requests\Roster;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $company = app(CompanyContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('work_schedules')->where('company_id', $company)->ignore($this->route('workSchedule')?->id)],
            /** Working days; days left out are off. day: 1 = Monday … 7 = Sunday. An end before the start runs past midnight. */
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.day' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.start' => ['required', 'date_format:H:i'],
            'days.*.end' => ['required', 'date_format:H:i', 'different:days.*.start'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'is_default' => ['boolean'],
            /** Departments or sections that use these hours. */
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Other working hours have this name.', 'days.*.day.distinct' => 'Each day can be listed once.', 'days.min' => 'Choose at least one working day.'];
    }
}
