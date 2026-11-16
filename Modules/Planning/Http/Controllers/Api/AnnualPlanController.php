<?php

namespace Modules\Planning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Actions\ManagePlanning;
use Modules\Planning\Http\Requests\AnnualPlanRequest;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Planning\Support\PlanningAccess;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Planning
 */
class AnnualPlanController extends Controller
{
    public function __construct(private readonly PlanningAccess $access, private readonly ManagePlanning $planning) {}

    /**
     * Annual plans (one per financial year), newest first.
     *
     * @response array{data: list<array{id: int, title: string, year: int, start_date: string, end_date: string, submission_due: string|null, status: string, status_label: string, strategic_plan: array{id: int, title: string}|null, departments: int, approved: int, activities: int}>, defaults: array{year: int, title: string, start_date: string, end_date: string}}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesAll($request->user()) || $this->access->departments($request->user()) !== [], 403);
        $plans = AnnualPlan::with('strategicPlan')->withCount(['departmentPlans as departments', 'departmentPlans as approved' => fn ($q) => $q->where('status', 'approved'), 'activities'])
            ->latest('year')->get();
        $next = ($plans->max('year') ?? (int) now()->year - 1) + 1;

        return response()->json(['data' => $plans->map(fn ($p) => $this->row($p))->values(), 'defaults' => $this->defaults($next)]);
    }

    /** @response array{data: array{id: int, title: string, year: int, start_date: string, end_date: string, submission_due: string|null, status: string, status_label: string, strategic_plan: array{id: int, title: string}|null, departments: int, approved: int, activities: int}} */
    public function store(AnnualPlanRequest $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validated();
        if (AnnualPlan::where('year', $data['year'])->exists()) {
            throw ValidationException::withMessages(['year' => 'There is already an annual plan for this year.']);
        }
        $plan = AnnualPlan::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($plan->load('strategicPlan'))], 201);
    }

    /** @response array{data: array{id: int, title: string, year: int, start_date: string, end_date: string, submission_due: string|null, status: string, status_label: string, strategic_plan: array{id: int, title: string}|null, departments: int, approved: int, activities: int}} */
    public function update(AnnualPlanRequest $request, AnnualPlan $plan): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        if ($plan->status === 'closed') {
            throw ValidationException::withMessages(['plan' => 'This annual plan is closed.']);
        }
        $data = $request->validated();
        if ($plan->activities()->whereNotNull('strategic_objective_id')->exists() && (int) ($data['strategic_plan_id'] ?? 0) !== (int) $plan->strategic_plan_id) {
            throw ValidationException::withMessages(['strategic_plan_id' => 'Activities are already linked to objectives of the current strategic plan.']);
        }
        $plan->update($data);
        $plan->activities()->update(['year' => $plan->year]);

        return response()->json(['data' => $this->row($plan->load('strategicPlan')->loadCount(['departmentPlans as departments', 'departmentPlans as approved' => fn ($q) => $q->where('status', 'approved'), 'activities']))]);
    }

    /**
     * An annual plan with its departments' plans (status, activities, progress). Heads see only their departments.
     *
     * @response array{data: array{id: int, title: string, year: int, start_date: string, end_date: string, submission_due: string|null, status: string, status_label: string, strategic_plan: array{id: int, title: string}|null, departments: int, approved: int, activities: int}, departments: list<array{id: int, department_id: int, name: string, status: string, status_label: string, activities: int, tasks: int, progress: int, waiting_for: string|null, can_plan: bool}>, can_manage: bool}
     */
    public function show(Request $request, AnnualPlan $plan): JsonResponse
    {
        $user = $request->user();
        $own = $this->access->departments($user);
        abort_unless($this->access->seesAll($user) || $own !== [], 403);
        $plan->load('strategicPlan')->loadCount(['departmentPlans as departments', 'departmentPlans as approved' => fn ($q) => $q->where('status', 'approved'), 'activities']);
        $dps = $plan->departmentPlans()->with(['department', 'workflow.steps'])->get()
            ->filter(fn ($dp) => $this->access->canView($user, $dp->department_id))->sortBy(fn ($dp) => $dp->department?->name);
        $stats = TaskActivity::where('annual_plan_id', $plan->id)->withCount('tasks')->withAvg(['tasks' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'progress')->get()->groupBy('department_id');

        return response()->json([
            'data' => $this->row($plan),
            'departments' => $dps->map(fn (DepartmentPlan $dp) => [
                'id' => $dp->id, 'department_id' => $dp->department_id, 'name' => $dp->department?->name ?? '—', 'status' => $dp->status,
                'status_label' => DepartmentPlan::STATUS_LABELS[$dp->status] ?? $dp->status,
                'activities' => ($stats[$dp->department_id] ?? collect())->count(), 'tasks' => (int) ($stats[$dp->department_id] ?? collect())->sum('tasks_count'),
                'progress' => (int) round((float) ($stats[$dp->department_id] ?? collect())->avg(fn ($a) => (float) ($a->tasks_avg_progress ?? 0))),
                'waiting_for' => $dp->status === 'pending' ? $dp->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'can_plan' => $plan->status === 'open' && $this->access->canPlan($user, $dp->department_id),
            ])->values(),
            'can_manage' => $this->access->manages($user),
        ]);
    }

    /**
     * Open the plan: every department gets a department plan to fill. Run again after adding departments.
     *
     * @response array{data: array{created: int}}
     */
    public function open(Request $request, AnnualPlan $plan): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);

        return response()->json(['data' => ['created' => $this->planning->open($plan)]]);
    }

    /** @response array{data: array{id: int, title: string, year: int, start_date: string, end_date: string, submission_due: string|null, status: string, status_label: string, strategic_plan: array{id: int, title: string}|null, departments: int, approved: int, activities: int}} */
    public function close(Request $request, AnnualPlan $plan): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);

        return response()->json(['data' => $this->row($this->planning->close($plan)->load('strategicPlan'))]);
    }

    /** @response array{message: string} */
    public function destroy(Request $request, AnnualPlan $plan): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        if ($plan->status !== 'draft' || $plan->activities()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Only a draft plan without activities can be deleted.']);
        }
        $plan->delete();

        return response()->json(['message' => 'Annual plan deleted.']);
    }

    private function defaults(int $year): array
    {
        [$m, $d] = array_map('intval', explode('-', app(CompanyContext::class)->get()?->fiscal_year_start ?: '01-01') + [1 => '01']);
        $start = Carbon::create($year, $m ?: 1, $d ?: 1);
        $label = $start->month === 1 && $start->day === 1 ? (string) $year : $year.'/'.substr((string) ($year + 1), -2);

        return ['year' => $year, 'title' => "Annual plan {$label}", 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addYear()->subDay()->toDateString()];
    }

    private function row(AnnualPlan $p): array
    {
        return [
            'id' => $p->id, 'title' => $p->title, 'year' => (int) $p->year, 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(),
            'submission_due' => $p->submission_due?->toDateString(), 'status' => $p->status, 'status_label' => AnnualPlan::STATUS_LABELS[$p->status] ?? $p->status,
            'strategic_plan' => $p->strategicPlan ? ['id' => $p->strategicPlan->id, 'title' => $p->strategicPlan->title] : null,
            'departments' => (int) ($p->departments ?? 0), 'approved' => (int) ($p->approved ?? 0), 'activities' => (int) ($p->activities_count ?? 0),
        ];
    }
}
