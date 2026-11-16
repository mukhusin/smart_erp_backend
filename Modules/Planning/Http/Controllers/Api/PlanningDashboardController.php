<?php

namespace Modules\Planning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Planning\Models\SubActivity;
use Modules\Planning\Support\PlanningAccess;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Planning
 */
class PlanningDashboardController extends Controller
{
    public function __construct(private readonly PlanningAccess $access) {}

    /**
     * Planning statistics for the dashboard: department plan status, activities per objective and quarter, and progress
     * of the tasks under the activities. Limited to the user's departments.
     *
     * @response array{plan: array{id: int, title: string, year: int, status: string, status_label: string, strategic_plan: string|null, submission_due: string|null}|null, plans: list<array{id: int, title: string, year: int}>, totals: array{departments: int, approved: int, pending: int, draft: int, activities: int, sub_activities: int, tasks: int, done: int, overdue: int, progress: float|null}, plan_status: list<array{status: string, label: string, count: int}>, task_status: list<array{status: string, label: string, count: int}>, objectives: list<array{id: int|null, label: string, activities: int, progress: float|null}>, quarters: list<array{label: string, activities: int, sub_activities: int}>, departments: list<array{id: int, name: string, status: string, status_label: string, activities: int, tasks: int, done: int, progress: float|null}>}
     */
    #[QueryParameter('plan_id', description: 'Defaults to the open or latest annual plan.', type: 'integer')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $departments = $this->access->departments($user);
        abort_unless($this->access->seesAll($user) || $departments !== [], 403);
        $plans = AnnualPlan::with('strategicPlan')->latest('year')->latest('id')->get();
        $plan = $request->query('plan_id') ? $plans->firstWhere('id', (int) $request->query('plan_id'))
            : ($plans->first(fn ($p) => $p->year === (int) now()->year) ?? $plans->firstWhere('status', 'open') ?? $plans->first());
        $list = $plans->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'year' => (int) $p->year])->values();
        $scope = fn ($q) => $departments === null ? $q : $q->whereIn('department_id', $departments);
        if (! $plan) {
            return response()->json(['plan' => null, 'plans' => $list, 'totals' => ['departments' => 0, 'approved' => 0, 'pending' => 0, 'draft' => 0, 'activities' => 0,
                'sub_activities' => 0, 'tasks' => 0, 'done' => 0, 'overdue' => 0, 'progress' => null], 'plan_status' => [], 'task_status' => [], 'objectives' => [], 'quarters' => [], 'departments' => []]);
        }

        $deptPlans = $scope(DepartmentPlan::with('department')->where('annual_plan_id', $plan->id))->get();
        $activities = $scope(TaskActivity::with('objective')->where('annual_plan_id', $plan->id))->get();
        $subs = SubActivity::whereIn('task_activity_id', $activities->pluck('id'))->get();
        $tasks = Task::whereIn('task_activity_id', $activities->pluck('id'))->where('status', '!=', 'cancelled')->get(['id', 'task_activity_id', 'status', 'progress', 'due_date']);
        $byActivity = $tasks->groupBy('task_activity_id');
        $avg = fn ($c) => $c->isEmpty() ? null : round($c->avg(fn ($t) => $t->status === 'done' ? 100 : (int) $t->progress), 1);
        $quarter = fn ($q, $set) => $set->filter(fn ($a) => in_array((string) $q, explode(',', (string) ($a->quarters ?: '1,2,3,4')), true))->count();

        return response()->json([
            'plan' => ['id' => $plan->id, 'title' => $plan->title, 'year' => (int) $plan->year, 'status' => $plan->status, 'status_label' => AnnualPlan::STATUS_LABELS[$plan->status] ?? $plan->status,
                'strategic_plan' => $plan->strategicPlan?->title, 'submission_due' => $plan->submission_due?->toDateString()],
            'plans' => $list,
            'totals' => [
                'departments' => $deptPlans->count(), 'approved' => $deptPlans->where('status', 'approved')->count(), 'pending' => $deptPlans->where('status', 'pending')->count(),
                'draft' => $deptPlans->whereIn('status', ['draft', 'rejected'])->count(), 'activities' => $activities->count(), 'sub_activities' => $subs->count(),
                'tasks' => $tasks->count(), 'done' => $tasks->where('status', 'done')->count(),
                'overdue' => $tasks->filter(fn ($t) => $t->status !== 'done' && $t->due_date && $t->due_date->lt(today()))->count(), 'progress' => $avg($tasks),
            ],
            'plan_status' => collect(DepartmentPlan::STATUS_LABELS)->map(fn ($label, $status) => ['status' => $status, 'label' => $label, 'count' => $deptPlans->where('status', $status)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
            'task_status' => collect(Task::STATUS_LABELS)->except('cancelled')->map(fn ($label, $status) => ['status' => $status, 'label' => $label, 'count' => $tasks->where('status', $status)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
            'objectives' => $activities->groupBy(fn ($a) => $a->strategic_objective_id ?? 0)->map(function ($g, $id) use ($byActivity, $avg) {
                $o = $g->first()->objective;

                return ['id' => $id ? (int) $id : null, 'label' => $o ? trim(($o->code ? $o->code.' ' : '').$o->title) : 'No objective', 'activities' => $g->count(),
                    'progress' => $avg($g->flatMap(fn ($a) => $byActivity[$a->id] ?? collect()))];
            })->sortBy('label')->values(),
            'quarters' => collect([1, 2, 3, 4])->map(fn ($q) => ['label' => 'Q'.$q, 'activities' => $quarter($q, $activities), 'sub_activities' => $quarter($q, $subs)])->values(),
            'departments' => $deptPlans->map(function (DepartmentPlan $dp) use ($activities, $byActivity, $avg) {
                $mine = $activities->where('department_id', $dp->department_id);
                $t = $mine->flatMap(fn ($a) => $byActivity[$a->id] ?? collect());

                return ['id' => (int) $dp->department_id, 'name' => (string) $dp->department?->name, 'status' => $dp->status, 'status_label' => DepartmentPlan::STATUS_LABELS[$dp->status] ?? $dp->status,
                    'activities' => $mine->count(), 'tasks' => $t->count(), 'done' => $t->where('status', 'done')->count(), 'progress' => $avg($t)];
            })->sortByDesc('activities')->values(),
        ]);
    }
}
