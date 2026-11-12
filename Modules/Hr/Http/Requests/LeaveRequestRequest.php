<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\EmployeeFiles;

class LeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('agreed'))) {
            $this->merge(['agreed' => filter_var($this->input('agreed'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    public function rules(): array
    {
        $byEmployee = $this->routeIs('self.*');

        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')->where('is_active', true)],
            'start_date' => ['required', 'date'],
            /** Days of leave, counted by the leave type's rule; the last day is worked out. */
            'days' => ['required', 'integer', 'min:1', 'max:366'],
            /** Why (optional). */
            'description' => ['nullable', 'string', 'max:1000'],
            'leave_address' => [$byEmployee ? 'required' : 'nullable', 'string', 'max:255'],
            'leave_phone' => [$byEmployee ? 'required' : 'nullable', 'string', 'max:30'],
            /** I confirm the request (employee). */
            'agreed' => [$byEmployee ? 'accepted' : 'nullable'],
            /** Compassionate leave: deceased_name, relationship, death_date. */
            'details' => ['nullable', 'array'],
            'details.deceased_name' => ['nullable', 'string', 'max:150'],
            'details.relationship' => ['nullable', 'string', 'max:50'],
            'details.death_date' => ['nullable', 'date'],
            /** Request this planned annual leave (from the annual leave roster). */
            'annual_plan_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', ...EmployeeFiles::DOCUMENT_RULES],
        ];
    }

    public function messages(): array
    {
        return [
            'leave_address.required' => 'Where will you be during the leave?', 'leave_phone.required' => 'Give a phone number you can be reached on.',
            'agreed.accepted' => 'Tick the confirmation to send your request.',
        ];
    }
}
