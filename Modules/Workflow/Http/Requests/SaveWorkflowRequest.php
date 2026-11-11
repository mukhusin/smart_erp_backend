<?php

namespace Modules\Workflow\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workflow\Models\WorkflowStep;

class SaveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();

        return [
            /** Empty = the company-wide workflow; a branch id = that branch's own workflow (overrides the company one). */
            'company_branch_id' => ['nullable', 'integer', Rule::exists('company_branches', 'id')->where('company_id', $companyId)],
            'is_active' => ['required', 'boolean'],
            /** Approval steps in order. An empty list removes the workflow (changes then apply at once). */
            'steps' => ['present', 'array', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:100'],
            /** role = anyone with the role; user = one person; department_head = head of the requester's department; supervisor = the employee's supervisor. */
            'steps.*.approver_type' => ['required', Rule::in([WorkflowStep::APPROVER_ROLE, WorkflowStep::APPROVER_USER, WorkflowStep::APPROVER_DEPARTMENT_HEAD, WorkflowStep::APPROVER_SUPERVISOR])],
            'steps.*.role_id' => ['nullable', 'required_if:steps.*.approver_type,role', 'integer', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'steps.*.user_id' => ['nullable', 'required_if:steps.*.approver_type,user', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)->where('is_active', true)],
            /** Role approvers: only people from the request's branch. */
            'steps.*.same_branch' => ['sometimes', 'boolean'],
            'steps.*.allow_send_back' => ['sometimes', 'boolean'],
            /** Button text, e.g. "Recommend" / "Decline". */
            'steps.*.approve_label' => ['nullable', 'string', 'max:40'],
            'steps.*.reject_label' => ['nullable', 'string', 'max:40'],
        ];
    }
}
