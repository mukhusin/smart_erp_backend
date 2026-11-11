<?php

namespace Modules\Hr\Http\Requests;

use App\Models\Department;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();
        $current = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:150'],
            /** Short code, e.g. NRS. Used in employee numbers with a department-code segment. */
            'short_name' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            /** Null = all branches. */
            'company_branch_id' => ['nullable', 'integer', Rule::exists('company_branches', 'id')->where('company_id', $companyId)],
            /** A department id makes this a section of it. Sections cannot have sections. */
            'parent_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)->whereNull('parent_id')->whereNull('deleted_at'),
                Rule::notIn(array_filter([$current?->id]))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /** Same name: allowed in different branches, refused next to a company-wide one or within the same branch. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $branch = $this->input('company_branch_id');
            $clash = Department::where('company_id', app(CompanyContext::class)->id())
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $this->input('name')))])
                ->where('parent_id', $this->input('parent_id'))
                ->when($this->route('department'), fn ($q, $d) => $q->whereKeyNot($d->id))
                ->when($branch, fn ($q) => $q->where(fn ($q) => $q->whereNull('company_branch_id')->orWhere('company_branch_id', $branch)))
                ->with('branch')->first();
            if ($clash) {
                $where = $clash->branch ? 'in '.$clash->branch->name : 'for all branches';
                $validator->errors()->add('name', 'A '.($this->input('parent_id') ? 'section' : 'department')." with this name already exists {$where}.");
            }
        }];
    }

    public function messages(): array
    {
        return ['short_name.regex' => 'Use letters and digits only.', 'parent_id.exists' => 'Choose a department (not a section).'];
    }
}
