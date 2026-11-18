<?php

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Workflow\Models\WorkflowAction;

class ActionRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Branch workflow (new actions only); empty for the company-wide one. */
            'company_branch_id' => ['nullable', 'integer', 'exists:company_branches,id'],
            /** started, step_approved, approved, rejected, sent_back, cancelled or overdue. */
            'event' => ['required', 'in:'.implode(',', array_keys(WorkflowAction::EVENTS))],
            /** Only for this step (step approved / overdue); empty = any step. */
            'step_order' => ['nullable', 'integer', 'min:1', 'max:20'],
            /** Only when the request amount is at least this. */
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            /** notify, escalate (overdue only) or webhook. */
            'action' => ['required', 'in:'.implode(',', array_keys(WorkflowAction::ACTIONS))],
            /** requester, role, user, department_head or approvers. */
            'target' => ['nullable', 'required_unless:action,webhook', 'in:'.implode(',', array_keys(WorkflowAction::TARGETS))],
            'role_id' => ['nullable', 'required_if:target,role', 'integer', 'exists:roles,id'],
            'user_id' => ['nullable', 'required_if:target,user', 'integer', 'exists:users,id'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:app,email,sms'],
            /** For "waiting too long": after how many days. */
            'after_days' => ['nullable', 'required_if:event,overdue', 'integer', 'min:1', 'max:90'],
            /** Optional text; may use {title}, {process}, {requester}, {step}, {amount}, {days}. */
            'message' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'required_if:action,webhook', 'url:https,http', 'max:500'],
            /** Signs each call (header X-Signature, HMAC-SHA256 of the body). Leave empty to keep the saved one. */
            'secret' => ['nullable', 'string', 'max:200'],
            'clear_secret' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
