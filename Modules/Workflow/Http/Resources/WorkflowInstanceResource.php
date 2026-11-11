<?php

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\WorkflowCatalog;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * A request going through approval.
 *
 * @mixin WorkflowInstance
 */
class WorkflowInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $process = app(WorkflowCatalog::class)->get($this->process);
        $summary = $this->subject ? $process->summary($this->subject) : ['title' => $process->name(), 'lines' => [], 'link' => null];
        $user = $request->user();
        $current = $this->currentStep();
        $canAct = app(WorkflowEngine::class)->canAct($user, $this->resource);

        return [
            'id' => $this->id,
            'process' => $this->process,
            'process_name' => $process->name(),
            'title' => $summary['title'],
            /** @var list<string> */
            'lines' => $summary['lines'],
            /** Where to see the record in the app. */
            'link' => $summary['link'],
            /** pending | approved | rejected | cancelled */
            'status' => $this->status,
            'requested_by' => $this->initiator?->display_name,
            'requested_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            /** @var array{name: string, approve_label: string, reject_label: string, allow_send_back: bool}|null */
            'current_step' => $current ? [
                'name' => $current->name,
                'approve_label' => $current->approve_label ?: 'Approve',
                'reject_label' => $current->reject_label ?: 'Reject',
                'allow_send_back' => (bool) $current->allow_send_back,
            ] : null,
            /** The current user can approve / reject / send back now. */
            'can_act' => $canAct,
            /** The current user (the requester) can withdraw it. */
            'can_cancel' => $this->status === WorkflowInstance::PENDING && $this->initiated_by === $user->id,
            /** Earlier steps this request can be sent back to. @var list<array{step_order: int, name: string}> */
            'send_back_options' => $canAct && $current?->allow_send_back
                ? $this->steps->where('step_order', '<', $current->step_order)->unique('step_order')->map(fn ($s) => ['step_order' => $s->step_order, 'name' => $s->name])->values()->all()
                : [],
            /** Timeline. @var list<array{step_order: int, name: string, approver: string, status: string, acted_by: string|null, acted_at: string|null, comment: string|null}> */
            'steps' => $this->steps->map(fn (WorkflowInstanceStep $s) => [
                'step_order' => $s->step_order,
                'name' => $s->name,
                'approver' => match ($s->approver_type) {
                    'role' => 'Role: ' . ($s->role?->name ?? '—') . ($s->same_branch ? ' (same branch)' : ''),
                    'user' => $s->user?->display_name ?? '—',
                    'department_head' => 'Head of department',
                    'supervisor' => 'Supervisor (or head of department)',
                    default => $s->approver_type,
                },
                'status' => $s->status,
                'acted_by' => $s->actor?->display_name,
                'acted_at' => $s->acted_at?->toIso8601String(),
                'comment' => $s->comment,
            ])->values()->all(),
        ];
    }
}
