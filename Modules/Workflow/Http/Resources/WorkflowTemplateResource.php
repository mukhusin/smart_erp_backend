<?php

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowStep;
use Modules\Workflow\Models\WorkflowTemplate;

/**
 * @mixin WorkflowTemplate
 */
class WorkflowTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var array{id: int, name: string}|null Null = company-wide. */
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            'is_active' => (bool) $this->is_active,
            /** @var list<array{id: int, step_order: int, name: string, approver_type: string, role_id: int|null, role_name: string|null, user_id: int|null, user_name: string|null, same_branch: bool, allow_send_back: bool, approve_label: string|null, reject_label: string|null}> */
            'steps' => $this->steps->map(fn (WorkflowStep $s) => [
                'id' => $s->id,
                'step_order' => $s->step_order,
                'name' => $s->name,
                'approver_type' => $s->approver_type,
                'role_id' => $s->role_id,
                'role_name' => $s->role?->name,
                'user_id' => $s->user_id,
                'user_name' => $s->user?->display_name,
                'same_branch' => (bool) $s->same_branch,
                'allow_send_back' => (bool) $s->allow_send_back,
                'approve_label' => $s->approve_label,
                'reject_label' => $s->reject_label,
            ])->values()->all(),
        ];
    }
}
