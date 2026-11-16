<?php

namespace Modules\Planning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Planning\Actions\ManagePlanning;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Planning\Support\PlanningAccess;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Planning
 */
class DepartmentPlanController extends Controller
{
    public function __construct(private readonly PlanningAccess $access, private readonly ManagePlanning $planning) {}

    /**
     * A department's plan for the year: its activities with outputs, targets, KPIs and progress from tasks.
     *
     * @response array{data: array{id: int, status: string, status_label: string, decision_comment: string|null, submitted_at: string|null, submitted_by: string|null, waiting_for: string|null}, plan: array{id: int, title: string, year: int, status: string, submission_due: string|null}, department: array{id: int, name: string}, objectives: list<array{id: int, label: string}>, activities: list<array{id: int, code: string|null, title: string, description: string|null, output: string|null, target: string|null, kpi: string|null, quarters: string|null, objective: array{id: int, label: string}|null, is_active: bool, tasks: int, open_tasks: int, progress: int}>, can: array{edit: bool, submit: bool, withdraw: bool, reopen: bool}}
     */
    public function show(Request $request, AnnualPlan $plan, Department $department): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $department->id), 403);
        $dp = $this->find($plan, $department);
        $activities = TaskActivity::with('objective')->where('annual_plan_id', $plan->id)->where('department_id', $department->id)
            ->withCount(['tasks', 'tasks as open_tasks_count' => fn ($q) => $q->whereIn('status', ['todo', 'in_progress', 'review', 'returned'])])
            ->withAvg(['tasks' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'progress')
            ->orderByRaw('code IS NULL')->orderBy('code')->orderBy('title')->get();
        $editable = $plan->status === 'open' && in_array($dp->status, ['draft', 'rejected'], true) && $this->access->canPlan($user, $department->id);
        $dp->load(['submitter', 'workflow.steps']);

        return response()->json([
            'data' => ['id' => $dp->id, 'status' => $dp->status, 'status_label' => DepartmentPlan::STATUS_LABELS[$dp->status] ?? $dp->status, 'decision_comment' => $dp->decision_comment,
                'submitted_at' => $dp->submitted_at?->toIso8601String(), 'submitted_by' => $dp->submitter?->display_name,
                'waiting_for' => $dp->status === 'pending' ? $dp->workflow?->steps->firstWhere('status', 'pending')?->name : null],
            'plan' => ['id' => $plan->id, 'title' => $plan->title, 'year' => (int) $plan->year, 'status' => $plan->status, 'submission_due' => $plan->submission_due?->toDateString()],
            'department' => ['id' => $department->id, 'name' => $department->name],
            'objectives' => $plan->strategicPlan ? $plan->strategicPlan->objectives->map(fn ($o) => ['id' => $o->id, 'label' => $o->label()])->values() : [],
            'activities' => $activities->map(fn (TaskActivity $a) => [
                'id' => $a->id, 'code' => $a->code, 'title' => $a->title, 'description' => $a->description, 'output' => $a->output, 'target' => $a->target, 'kpi' => $a->kpi,
                'quarters' => $a->quarters, 'objective' => $a->objective ? ['id' => $a->objective->id, 'label' => $a->objective->label()] : null, 'is_active' => (bool) $a->is_active,
                'tasks' => (int) $a->tasks_count, 'open_tasks' => (int) $a->open_tasks_count, 'progress' => (int) round((float) ($a->tasks_avg_progress ?? 0)),
            ])->values(),
            'can' => [
                'edit' => $editable,
                'submit' => $editable,
                'withdraw' => $dp->status === 'pending' && $dp->submitted_by === $user->id,
                'reopen' => $dp->status === 'approved' && $plan->status === 'open' && $this->access->manages($user),
            ],
        ]);
    }

    /** @response array{data: array{id: int}} */
    public function storeActivity(Request $request, AnnualPlan $plan, Department $department): JsonResponse
    {
        abort_unless($this->access->canPlan($request->user(), $department->id), 403);
        $a = $this->planning->saveActivity($this->find($plan, $department), $this->validated($request), $request->user());

        return response()->json(['data' => ['id' => $a->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateActivity(Request $request, AnnualPlan $plan, Department $department, TaskActivity $activity): JsonResponse
    {
        abort_unless($this->access->canPlan($request->user(), $department->id), 403);
        abort_unless($activity->annual_plan_id === $plan->id && $activity->department_id === $department->id, 404);
        $a = $this->planning->saveActivity($this->find($plan, $department), $this->validated($request), $request->user(), $activity);

        return response()->json(['data' => ['id' => $a->id]]);
    }

    /** @response array{message: string} */
    public function destroyActivity(Request $request, AnnualPlan $plan, Department $department, TaskActivity $activity): JsonResponse
    {
        abort_unless($this->access->canPlan($request->user(), $department->id), 403);
        abort_unless($activity->annual_plan_id === $plan->id && $activity->department_id === $department->id, 404);
        $this->planning->deleteActivity($this->find($plan, $department), $activity);

        return response()->json(['message' => 'Activity removed.']);
    }

    /**
     * Submit, withdraw, or (planning managers) reopen an approved department plan.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function action(Request $request, AnnualPlan $plan, Department $department, string $action): JsonResponse
    {
        $user = $request->user();
        $dp = $this->find($plan, $department);
        $dp = match ($action) {
            'submit' => $this->access->canPlan($user, $department->id) ? $this->planning->submit($dp, $user) : abort(403),
            'withdraw' => $dp->submitted_by === $user->id ? $this->planning->withdraw($dp, $user) : abort(403),
            'reopen' => $this->access->manages($user) ? $this->planning->reopen($dp) : abort(403),
            default => abort(404),
        };

        return response()->json(['data' => ['id' => $dp->id, 'status' => $dp->status]]);
    }

    private function find(AnnualPlan $plan, Department $department): DepartmentPlan
    {
        return DepartmentPlan::where('annual_plan_id', $plan->id)->where('department_id', $department->id)->firstOr(fn () => abort(404, 'This department has no plan for the year. Open the annual plan first.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'output' => ['nullable', 'string', 'max:500'],
            'target' => ['nullable', 'string', 'max:500'],
            'kpi' => ['nullable', 'string', 'max:500'],
            'quarters' => ['nullable', 'string', 'regex:/^[1-4](,[1-4]){0,3}$/'],
            'strategic_objective_id' => ['nullable', 'integer'],
        ]);
    }
}
