<?php

namespace Modules\Hr\Http\Requests;

use App\Models\JobPosition;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class JobPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            /** The department (any branch's) this position belongs to. */
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', app(CompanyContext::class)->id())->whereNull('parent_id')->whereNull('deleted_at')],
            /** Rich text (HTML); unsafe or pasted Office markup is removed. */
            'description' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ];
    }

    /** Titles are unique within a department (each branch's department can have its own "Registered Nurse"). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $taken = JobPosition::withTrashed()->where('department_id', $this->integer('department_id'))
                ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim((string) $this->input('title')))])
                ->when($this->route('jobPosition'), fn ($q, $p) => $q->whereKeyNot($p->id))->exists();
            if ($taken) {
                $validator->errors()->add('title', 'This department already has a position with this title.');
            }
        }];
    }

    public function messages(): array
    {
        return ['department_id.required' => 'Choose the department.', 'department_id.exists' => 'Choose a department (not a section).'];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        if (array_key_exists('description', $data)) {
            $data['description'] = \Modules\Core\Support\RichText::clean($data['description']);
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
