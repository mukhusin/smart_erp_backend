<?php

namespace Modules\Tasks\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'task_activity_id' => ['nullable', 'integer', Rule::exists('task_activities', 'id')->where('company_id', app(CompanyContext::class)->id())],
            'parent_id' => $creating ? ['nullable', 'integer', Rule::exists('tasks', 'id')->where('company_id', app(CompanyContext::class)->id())] : ['prohibited'],
            'employee_ids' => $creating ? ['nullable', 'array', 'max:100'] : ['prohibited'],
            'employee_ids.*' => ['integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'checklist' => ['nullable', 'array', 'max:50'],
            'checklist.*.text' => ['required', 'string', 'max:200'],
            'checklist.*.done' => ['sometimes', 'boolean'],
            'recurrence' => ['nullable', Rule::in(['weekly', 'monthly'])],
        ];
    }
}
