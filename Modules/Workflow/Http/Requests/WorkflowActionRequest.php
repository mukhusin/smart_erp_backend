<?php

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkflowActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $needsComment = $this->routeIs('workflow.reject', 'workflow.send-back');

        return [
            /** Required when rejecting or sending back. */
            'comment' => [$needsComment ? 'required' : 'nullable', 'string', 'max:2000'],
            /** Send back only: the step order to return to. */
            'to_step' => [$this->routeIs('workflow.send-back') ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }
}
