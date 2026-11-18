<?php

namespace Modules\Workflow\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\ErpModule;
use App\Models\User;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;
use Modules\Workflow\Http\Requests\SaveWorkflowRequest;
use Modules\Workflow\Http\Resources\WorkflowTemplateResource;
use Modules\Workflow\Models\WorkflowTemplate;
use Modules\Workflow\Services\WorkflowCatalog;
use Spatie\Permission\Models\Role;

/**
 * The company admin sets up approval workflows for the company's processes.
 *
 * @tags Admin: Approval workflows
 */
class WorkflowSetupController extends Controller
{
    /**
     * Branches and roles to choose from when setting up a workflow.
     *
     * @response array{data: array{branches: list<array{id: int, name: string}>, roles: list<array{id: int, name: string}>}}
     */
    public function options(CompanyContext $context): JsonResponse
    {
        return response()->json(['data' => [
            'branches' => CompanyBranch::query()->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'roles' => Role::where('company_id', $context->id())->orderByDesc('is_system')->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
        ]]);
    }

    /**
     * Active users of the company to pick as an approver (up to 20).
     *
     * @response array{data: list<array{id: int, name: string, detail: string|null}>}
     */
    #[QueryParameter('search', description: 'Name, username, email or employee number.', type: 'string')]
    public function users(Request $request, CompanyContext $context): JsonResponse
    {
        $term = trim((string) $request->query('search'));
        $users = User::with('employee.jobPosition')->where('company_id', $context->id())->where('is_active', true)
            ->when($term, fn ($q) => $q->where(fn ($q) => $q->where('username', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->where('fname', 'like', "%{$term}%")->orWhere('lname', 'like', "%{$term}%")->orWhere('employee_no', 'like', "%{$term}%"))))
            ->orderBy('username')->limit(20)->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->display_name,
            'detail' => implode(' · ', array_filter([$u->username, $u->employee?->jobPosition?->title])) ?: null,
        ])->values()->all()]);
    }

    /**
     * List processes and their workflows.
     *
     * Every process the company can use. A process with no active workflow applies changes at once.
     *
     * Ordered by module (as in the module list), so the screen can group them; `module_name` and `module_icon`
     * (Bootstrap icon name) describe the module.
     *
     * @response array{data: list<array{process: string, name: string, description: string, module: string, has_amount: bool, module_name: string, module_icon: string, workflows: list<WorkflowTemplateResource>}>}
     */
    public function index(WorkflowCatalog $catalog): JsonResponse
    {
        $templates = WorkflowTemplate::with(['steps.role', 'steps.user', 'branch'])->get()->groupBy('process');
        $modules = ErpModule::get(['slug', 'name', 'icon', 'sort_order'])->keyBy('slug');

        return response()->json(['data' => collect($catalog->forCurrentCompany())
            ->sortBy(fn (WorkflowProcess $p) => [$modules[$p->module()]?->sort_order ?? PHP_INT_MAX, $p->module()])
            ->map(fn (WorkflowProcess $p) => [
                'process' => $p->key(),
                'name' => $p->name(),
                'description' => $p->description(),
                'module' => $p->module(),
                'has_amount' => $p instanceof HasAmount,
                'module_name' => $modules[$p->module()]?->name ?? ucfirst($p->module()),
                'module_icon' => preg_replace('/^bi-/', '', (string) ($modules[$p->module()]?->icon ?: 'grid')),
                'workflows' => WorkflowTemplateResource::collection(($templates[$p->key()] ?? collect())->sortBy(fn ($t) => $t->company_branch_id ?? 0)->values())->resolve(),
            ])->values()]);
    }

    /**
     * Save a process's workflow.
     *
     * Replaces the steps of the company-wide workflow (or a branch's own one). Requests already in progress keep
     * the steps they started with. An empty step list removes the workflow.
     */
    public function save(SaveWorkflowRequest $request, string $process, WorkflowCatalog $catalog): JsonResponse
    {
        abort_unless(array_key_exists($process, $catalog->forCurrentCompany()), 404);
        $data = $request->validated();
        $branchId = $data['company_branch_id'] ?? null;

        $template = DB::transaction(function () use ($process, $data, $branchId, $request) {
            $template = WorkflowTemplate::where('process', $process)->where('company_branch_id', $branchId)->first();

            if (empty($data['steps'])) {
                $template?->delete();

                return null;
            }

            $template ??= new WorkflowTemplate(['process' => $process, 'company_branch_id' => $branchId]);
            $template->fill(['is_active' => $data['is_active'], 'updated_by' => $request->user()->id])->save();
            $template->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $template->steps()->create([
                    'step_order' => $i + 1,
                    'name' => $step['name'],
                    'approver_type' => $step['approver_type'],
                    'role_id' => $step['approver_type'] === 'role' ? $step['role_id'] : null,
                    'user_id' => $step['approver_type'] === 'user' ? $step['user_id'] : null,
                    'same_branch' => $step['approver_type'] === 'role' && ($step['same_branch'] ?? false),
                    'allow_send_back' => $step['allow_send_back'] ?? true,
                    'min_amount' => $step['min_amount'] ?? null,
                    'approve_label' => $step['approve_label'] ?? null,
                    'reject_label' => $step['reject_label'] ?? null,
                ]);
            }

            return $template->load(['steps.role', 'steps.user', 'branch']);
        });

        return response()->json(['data' => $template ? (new WorkflowTemplateResource($template))->resolve() : null]);
    }

    /**
     * Export every workflow of the company to move it to another environment (e.g. test → production).
     *
     * Roles, users and branches are written by name (users by email or username), not by id, so they match on the other side.
     *
     * @response array{format: string, version: int, exported_at: string, company: string|null, workflows: list<array{process: string, name: string, branch: string|null, is_active: bool, steps: list<array{name: string, approver_type: string, role: string|null, user: string|null, same_branch: bool, allow_send_back: bool, min_amount: float|null, approve_label: string|null, reject_label: string|null}>, actions: list<array{event: string, step_order: int|null, min_amount: float|null, action: string, target: string|null, role: string|null, user: string|null, channels: list<string>|null, after_days: int|null, message: string|null, url: string|null, is_active: bool}>}>}
     */
    public function export(WorkflowCatalog $catalog, CompanyContext $context): JsonResponse
    {
        $processes = $catalog->forCurrentCompany();
        $templates = WorkflowTemplate::with(['steps.role', 'steps.user', 'branch', 'actions.role', 'actions.user'])->orderBy('process')->orderBy('company_branch_id')->get()
            ->filter(fn ($t) => isset($processes[$t->process]));

        return response()->json([
            'format' => 'smart-erp-workflows', 'version' => 1, 'exported_at' => now()->toIso8601String(), 'company' => $context->get()?->name,
            'workflows' => $templates->map(fn (WorkflowTemplate $t) => ['process' => $t->process, 'name' => $processes[$t->process]->name(), 'branch' => $t->branch?->name, 'is_active' => (bool) $t->is_active,
                'steps' => $t->steps->sortBy('step_order')->map(fn ($s) => ['name' => $s->name, 'approver_type' => $s->approver_type, 'role' => $s->role?->name,
                    'user' => $s->user ? ($s->user->email ?: $s->user->username) : null, 'same_branch' => (bool) $s->same_branch, 'allow_send_back' => (bool) $s->allow_send_back,
                    'min_amount' => $s->min_amount, 'approve_label' => $s->approve_label, 'reject_label' => $s->reject_label])->values(),
                'actions' => $t->actions->map(fn ($a) => ['event' => $a->event, 'step_order' => $a->step_order, 'min_amount' => $a->min_amount, 'action' => $a->action, 'target' => $a->target,
                    'role' => $a->role?->name, 'user' => $a->user ? ($a->user->email ?: $a->user->username) : null, 'channels' => $a->channels, 'after_days' => $a->after_days,
                    'message' => $a->message, 'url' => $a->url, 'is_active' => (bool) $a->is_active])->values()])->values(),
        ]);
    }

    /**
     * Import workflows exported from another environment.
     *
     * Each workflow replaces the one for the same process and branch. Roles, users and branches are matched by name;
     * a workflow with anything that cannot be matched is skipped and reported. With `dry_run` nothing is saved and the
     * result shows what would happen.
     *
     * @response array{data: array{applied: int, skipped: int, dry_run: bool, results: list<array{process: string, name: string, branch: string|null, action: string, problems: list<string>}>}}
     */
    public function import(Request $request, WorkflowCatalog $catalog, CompanyContext $context): JsonResponse
    {
        $data = $request->validate([
            /** Must be smart-erp-workflows. */
            'format' => ['required', 'in:smart-erp-workflows'],
            'version' => ['required', 'integer', 'in:1'],
            /** True to check the file without saving. */
            'dry_run' => ['nullable', 'boolean'],
            'workflows' => ['required', 'array', 'max:500'],
            'workflows.*.process' => ['required', 'string', 'max:60'],
            'workflows.*.branch' => ['nullable', 'string', 'max:150'],
            'workflows.*.is_active' => ['nullable', 'boolean'],
            'workflows.*.steps' => ['present', 'array', 'max:20'],
            'workflows.*.steps.*.name' => ['required', 'string', 'max:100'],
            'workflows.*.steps.*.approver_type' => ['required', 'in:role,user,department_head,section_head,supervisor'],
            'workflows.*.steps.*.role' => ['nullable', 'string', 'max:150'],
            'workflows.*.steps.*.user' => ['nullable', 'string', 'max:150'],
            'workflows.*.steps.*.same_branch' => ['nullable', 'boolean'],
            'workflows.*.steps.*.allow_send_back' => ['nullable', 'boolean'],
            'workflows.*.steps.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'workflows.*.steps.*.approve_label' => ['nullable', 'string', 'max:40'],
            'workflows.*.steps.*.reject_label' => ['nullable', 'string', 'max:40'],
            /** Background actions (optional; a webhook secret is never exported). */
            'workflows.*.actions' => ['nullable', 'array', 'max:50'],
            'workflows.*.actions.*.event' => ['required', 'in:'.implode(',', array_keys(\Modules\Workflow\Models\WorkflowAction::EVENTS))],
            'workflows.*.actions.*.step_order' => ['nullable', 'integer', 'min:1'],
            'workflows.*.actions.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'workflows.*.actions.*.action' => ['required', 'in:'.implode(',', array_keys(\Modules\Workflow\Models\WorkflowAction::ACTIONS))],
            'workflows.*.actions.*.target' => ['nullable', 'in:'.implode(',', array_keys(\Modules\Workflow\Models\WorkflowAction::TARGETS))],
            'workflows.*.actions.*.role' => ['nullable', 'string', 'max:150'],
            'workflows.*.actions.*.user' => ['nullable', 'string', 'max:150'],
            'workflows.*.actions.*.channels' => ['nullable', 'array'],
            'workflows.*.actions.*.after_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'workflows.*.actions.*.message' => ['nullable', 'string', 'max:500'],
            'workflows.*.actions.*.url' => ['nullable', 'string', 'max:500'],
            'workflows.*.actions.*.is_active' => ['nullable', 'boolean'],
        ]);
        $dry = (bool) ($data['dry_run'] ?? false);
        $processes = $catalog->forCurrentCompany();
        $roles = Role::where('company_id', $context->id())->pluck('id', 'name');
        $branches = CompanyBranch::query()->pluck('id', 'name');
        $users = User::where('company_id', $context->id())->where('is_active', true)->get(['id', 'email', 'username']);
        $results = [];
        $applied = 0;
        foreach ($data['workflows'] as $w) {
            $problems = [];
            $process = $processes[$w['process']] ?? null;
            if (! $process) {
                $problems[] = "Process {$w['process']} is not used by this company (its module may be off).";
            }
            $branchId = null;
            if (! empty($w['branch'])) {
                $branchId = $branches[$w['branch']] ?? null;
                if (! $branchId) {
                    $problems[] = "Branch {$w['branch']} not found.";
                }
            }
            $steps = [];
            foreach ($w['steps'] as $i => $st) {
                $roleId = null;
                $userId = null;
                if ($st['approver_type'] === 'role') {
                    $roleId = $roles[$st['role'] ?? ''] ?? null;
                    if (! $roleId) {
                        $problems[] = 'Step '.($i + 1).': role '.($st['role'] ?? '(none)').' not found.';
                    }
                }
                if ($st['approver_type'] === 'user') {
                    $u = $users->first(fn ($u) => $st['user'] !== null && ($u->email === $st['user'] || $u->username === $st['user']));
                    $userId = $u?->id;
                    if (! $userId) {
                        $problems[] = 'Step '.($i + 1).': user '.($st['user'] ?? '(none)').' not found or inactive.';
                    }
                }
                $steps[] = ['step_order' => $i + 1, 'name' => $st['name'], 'approver_type' => $st['approver_type'], 'role_id' => $roleId, 'user_id' => $userId,
                    'same_branch' => $st['approver_type'] === 'role' && ($st['same_branch'] ?? false), 'allow_send_back' => $st['allow_send_back'] ?? true,
                    'min_amount' => $st['min_amount'] ?? null, 'approve_label' => $st['approve_label'] ?? null, 'reject_label' => $st['reject_label'] ?? null];
            }
            $actions = [];
            foreach ($w['actions'] ?? [] as $i => $ac) {
                $roleId = ! empty($ac['role']) ? ($roles[$ac['role']] ?? null) : null;
                $userId = ! empty($ac['user']) ? $users->first(fn ($u) => $u->email === $ac['user'] || $u->username === $ac['user'])?->id : null;
                if (($ac['target'] ?? null) === 'role' && ! $roleId) {
                    $problems[] = 'Action '.($i + 1).': role '.($ac['role'] ?? '(none)').' not found.';
                }
                if (($ac['target'] ?? null) === 'user' && ! $userId) {
                    $problems[] = 'Action '.($i + 1).': user '.($ac['user'] ?? '(none)').' not found or inactive.';
                }
                $actions[] = ['event' => $ac['event'], 'step_order' => $ac['step_order'] ?? null, 'min_amount' => $ac['min_amount'] ?? null, 'action' => $ac['action'], 'target' => $ac['target'] ?? null,
                    'role_id' => $roleId, 'user_id' => $userId, 'channels' => $ac['channels'] ?? null, 'after_days' => $ac['after_days'] ?? null, 'message' => $ac['message'] ?? null,
                    'url' => $ac['url'] ?? null, 'is_active' => $ac['is_active'] ?? true];
            }
            $existing = $process ? WorkflowTemplate::where('process', $w['process'])->where('company_branch_id', $branchId)->exists() : false;
            $action = $problems ? 'skipped' : (empty($steps) ? ($existing ? 'removed' : 'unchanged') : ($existing ? 'replaced' : 'created'));
            if (! $problems && ! $dry) {
                DB::transaction(function () use ($w, $branchId, $steps, $request, $actions) {
                    $template = WorkflowTemplate::where('process', $w['process'])->where('company_branch_id', $branchId)->first();
                    if (empty($steps)) {
                        $template?->delete();

                        return;
                    }
                    $template ??= new WorkflowTemplate(['process' => $w['process'], 'company_branch_id' => $branchId]);
                    $template->fill(['is_active' => $w['is_active'] ?? true, 'updated_by' => $request->user()->id])->save();
                    $template->steps()->delete();
                    foreach ($steps as $st) {
                        $template->steps()->create($st);
                    }
                    if (array_key_exists('actions', $w)) {
                        $template->actions()->delete();
                        foreach ($actions as $ac) {
                            $template->actions()->create($ac);
                        }
                    }
                });
            }
            if (! $problems) {
                $applied++;
            }
            $results[] = ['process' => $w['process'], 'name' => $process?->name() ?? $w['process'], 'branch' => $w['branch'] ?? null, 'action' => $action, 'problems' => $problems];
        }

        return response()->json(['data' => ['applied' => $applied, 'skipped' => count($results) - $applied, 'dry_run' => $dry, 'results' => $results]]);
    }
}
