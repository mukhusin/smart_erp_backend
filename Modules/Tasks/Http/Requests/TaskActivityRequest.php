<?php

namespace Modules\Tasks\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskActivityRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'year' => [$req, 'integer', 'between:2000,2100'],
            'department_id' => [$req, 'integer', Rule::exists('departments', 'id')->where('company_id', app(CompanyContext::class)->id())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
