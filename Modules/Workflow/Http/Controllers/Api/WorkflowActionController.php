<?php

namespace Modules\Workflow\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Models\WorkflowAction;
use Modules\Workflow\Models\WorkflowActionLog;
use Modules\Workflow\Models\WorkflowTemplate;
use Modules\Workflow\Services\WorkflowCatalog;
use Modules\Workflow\Support\ProcessEffects;

/**
 * @tags Admin: workflow actions
 */
class WorkflowActionController extends Controller
{
    /**
     * What a process does by itself, and the background actions attached to its workflow.
     *
     * @response array{data: array{effects: array{started: string|null, approved: string, rejected: string}, template_id: int|null, actions: list<array{id: int, event: string, event_label: string, step_order: int|null, min_amount: float|null, action: string, action_label: string, target: string|null, target_label: string|null, role_id: int|null, role: string|null, user_id: int|null, user: string|null, channels: list<string>, after_days: int|null, message: string|null, url: string|null, secret_set: bool, is_active: bool}>, logs: list<array{id: int, action_id: int, event: string, status: string, detail: string|null, request_id: int|null, at: string}>, options: array{events: list<array{value: string, label: string}>, actions: list<array{value: string, label: string}>, targets: list<array{value: string, label: string}>, channels: list<array{value: string, label: string}>}}}
     */
    #[QueryParameter('company_branch_id', description: 'The branch workflow; empty for the company-wide one.', type: 'integer')]
    public function index(Request $request, string $process, WorkflowCatalog $catalog): JsonResponse
    {
        abort_unless(array_key_exists($process, $catalog->forCurrentCompany()), 404);
        $template = $this->template($process, $request->query('company_branch_id'));
        $actions = $template ? $template->actions()->with(['role', 'user'])->get() : collect();
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => [
            'effects' => ProcessEffects::for($process),
            'template_id' => $template?->id,
            'actions' => $actions->map(fn (WorkflowAction $a) => ['id' => $a->id, 'event' => $a->event, 'event_label' => WorkflowAction::EVENTS[$a->event] ?? $a->event, 'step_order' => $a->step_order,
                'min_amount' => $a->min_amount, 'action' => $a->action, 'action_label' => WorkflowAction::ACTIONS[$a->action] ?? $a->action, 'target' => $a->target,
                'target_label' => $a->target ? (WorkflowAction::TARGETS[$a->target] ?? $a->target) : null, 'role_id' => $a->role_id, 'role' => $a->role?->name, 'user_id' => $a->user_id,
                'user' => $a->user?->display_name, 'channels' => $a->channels ?? [], 'after_days' => $a->after_days, 'message' => $a->message, 'url' => $a->url, 'secret_set' => (bool) $a->secret,
                'is_active' => (bool) $a->is_active])->values(),
            'logs' => $template ? WorkflowActionLog::whereIn('workflow_action_id', $actions->pluck('id'))->latest('id')->limit(50)->get()->map(fn ($l) => ['id' => $l->id,
                'action_id' => $l->workflow_action_id, 'event' => $l->event, 'status' => $l->status, 'detail' => $l->detail, 'request_id' => $l->workflow_instance_id, 'at' => $l->created_at->toIso8601String()])->values() : [],
            'options' => ['events' => $opt(WorkflowAction::EVENTS), 'actions' => $opt(WorkflowAction::ACTIONS), 'targets' => $opt(WorkflowAction::TARGETS), 'channels' => $opt(WorkflowAction::CHANNELS)],
        ]]);
    }

    /** @response array{data: array{id: int}} */
    public function store(\Modules\Workflow\Http\Requests\ActionRuleRequest $request, string $process, WorkflowCatalog $catalog): JsonResponse
    {
        abort_unless(array_key_exists($process, $catalog->forCurrentCompany()), 404);
        $data = $request->validated();
        $template = $this->template($process, $data['company_branch_id'] ?? null);
        if (! $template) {
            throw ValidationException::withMessages(['event' => 'Set the approval steps of this workflow first.']);
        }
        $a = $template->actions()->create($this->clean($data));

        return response()->json(['data' => ['id' => $a->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function update(\Modules\Workflow\Http\Requests\ActionRuleRequest $request, WorkflowAction $action): JsonResponse
    {
        $data = $request->validated();
        $clean = $this->clean($data);
        if (($data['secret'] ?? '') === '' && empty($data['clear_secret'])) {
            unset($clean['secret']);
        }
        $action->update($clean);

        return response()->json(['data' => ['id' => $action->id]]);
    }

    public function destroy(WorkflowAction $action): JsonResponse
    {
        $action->delete();

        return response()->json(['message' => 'Action removed.']);
    }

    private function clean(array $d): array
    {
        if ($d['action'] === 'escalate' && ($d['event'] !== 'overdue' || ! in_array($d['target'] ?? '', ['role', 'user'], true))) {
            throw ValidationException::withMessages(['action' => 'Escalation is for steps waiting too long, to a role or a person.']);
        }

        return ['event' => $d['event'], 'step_order' => $d['step_order'] ?? null, 'min_amount' => $d['min_amount'] ?? null, 'action' => $d['action'],
            'target' => $d['action'] === 'webhook' ? null : ($d['target'] ?? null), 'role_id' => ($d['target'] ?? null) === 'role' ? $d['role_id'] : null,
            'user_id' => ($d['target'] ?? null) === 'user' ? $d['user_id'] : null, 'channels' => $d['action'] === 'notify' ? array_values(array_unique($d['channels'] ?? ['app'])) : null,
            'after_days' => $d['event'] === 'overdue' ? $d['after_days'] : null, 'message' => $d['message'] ?? null, 'url' => $d['action'] === 'webhook' ? $d['url'] : null,
            'secret' => $d['action'] === 'webhook' && empty($d['clear_secret']) ? ($d['secret'] ?? null) : null, 'is_active' => (bool) ($d['is_active'] ?? true)];
    }

    private function template(string $process, $branchId): ?WorkflowTemplate
    {
        return WorkflowTemplate::where('process', $process)->where('company_branch_id', $branchId ?: null)->first();
    }
}
