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
     * @response array{data: list<array{process: string, name: string, description: string, module: string, module_name: string, module_icon: string, workflows: list<WorkflowTemplateResource>}>}
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
                    'approve_label' => $step['approve_label'] ?? null,
                    'reject_label' => $step['reject_label'] ?? null,
                ]);
            }

            return $template->load(['steps.role', 'steps.user', 'branch']);
        });

        return response()->json(['data' => $template ? (new WorkflowTemplateResource($template))->resolve() : null]);
    }
}
