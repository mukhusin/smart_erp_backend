<?php

namespace Modules\Planning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Models\StrategicObjective;
use Modules\Planning\Models\StrategicPlan;
use Modules\Planning\Support\PlanningAccess;

/**
 * @tags Planning
 */
class StrategicPlanController extends Controller
{
    public function __construct(private readonly PlanningAccess $access) {}

    /**
     * Strategic plans (several years), newest first.
     *
     * @response array{data: list<array{id: int, title: string, start_year: int, end_year: int, vision: string|null, mission: string|null, status: string, status_label: string, objectives: int, annual_plans: int}>}
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureSees($request);
        $plans = StrategicPlan::withCount(['objectives', 'annualPlans'])->latest('start_year')->get();

        return response()->json(['data' => $plans->map(fn ($p) => $this->row($p))->values()]);
    }

    /** @response array{data: array{id: int, title: string, start_year: int, end_year: int, vision: string|null, mission: string|null, status: string, status_label: string, objectives: int, annual_plans: int}} */
    public function store(Request $request): JsonResponse
    {
        $this->ensureManage($request);
        $plan = StrategicPlan::create($this->validated($request) + ['status' => 'draft', 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($plan)], 201);
    }

    /** @response array{data: array{id: int, title: string, start_year: int, end_year: int, vision: string|null, mission: string|null, status: string, status_label: string, objectives: int, annual_plans: int}} */
    public function update(Request $request, StrategicPlan $plan): JsonResponse
    {
        $this->ensureManage($request);
        $data = $this->validated($request) + $request->validate(['status' => ['sometimes', Rule::in(array_keys(StrategicPlan::STATUS_LABELS))]]);
        if (($data['status'] ?? null) === 'active') {
            StrategicPlan::where('id', '!=', $plan->id)->where('status', 'active')->update(['status' => 'archived']);
        }
        $plan->update($data);

        return response()->json(['data' => $this->row($plan->loadCount(['objectives', 'annualPlans']))]);
    }

    /**
     * A strategic plan with its objectives and, per objective, the activities planned for it and their progress.
     *
     * @response array{data: array{id: int, title: string, start_year: int, end_year: int, vision: string|null, mission: string|null, status: string, status_label: string, objectives: int, annual_plans: int}, objectives: list<array{id: int, code: string|null, title: string, description: string|null, sort_order: int, activities: int, progress: int}>, years: list<array{id: int, year: int, title: string, status: string}>}
     */
    public function show(Request $request, StrategicPlan $plan): JsonResponse
    {
        $this->ensureSees($request);
        $plan->loadCount(['objectives', 'annualPlans']);
        $objectives = $plan->objectives()->withCount('activities')->with(['activities' => fn ($q) => $q->withAvg(['tasks' => fn ($t) => $t->where('status', '!=', 'cancelled')], 'progress')])->get();

        return response()->json([
            'data' => $this->row($plan),
            'objectives' => $objectives->map(fn (StrategicObjective $o) => [
                'id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'description' => $o->description, 'sort_order' => (int) $o->sort_order,
                'activities' => (int) $o->activities_count, 'progress' => (int) round((float) $o->activities->avg(fn ($a) => (float) ($a->tasks_avg_progress ?? 0))),
            ])->values(),
            'years' => $plan->annualPlans()->orderBy('year')->get()->map(fn ($a) => ['id' => $a->id, 'year' => (int) $a->year, 'title' => $a->title, 'status' => $a->status])->values(),
        ]);
    }

    /** @response array{message: string} */
    public function destroy(Request $request, StrategicPlan $plan): JsonResponse
    {
        $this->ensureManage($request);
        if ($plan->annualPlans()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Annual plans follow this strategic plan; archive it instead.']);
        }
        $plan->delete();

        return response()->json(['message' => 'Strategic plan deleted.']);
    }

    /** @response array{data: array{id: int, code: string|null, title: string, description: string|null, sort_order: int, activities: int, progress: int}} */
    public function storeObjective(Request $request, StrategicPlan $plan): JsonResponse
    {
        $this->ensureManage($request);
        $o = $plan->objectives()->create($this->objective($request));

        return response()->json(['data' => $this->objectiveRow($o)], 201);
    }

    /** @response array{data: array{id: int, code: string|null, title: string, description: string|null, sort_order: int, activities: int, progress: int}} */
    public function updateObjective(Request $request, StrategicPlan $plan, StrategicObjective $objective): JsonResponse
    {
        $this->ensureManage($request);
        abort_unless($objective->strategic_plan_id === $plan->id, 404);
        $objective->update($this->objective($request));

        return response()->json(['data' => $this->objectiveRow($objective)]);
    }

    /** @response array{message: string} */
    public function destroyObjective(Request $request, StrategicPlan $plan, StrategicObjective $objective): JsonResponse
    {
        $this->ensureManage($request);
        abort_unless($objective->strategic_plan_id === $plan->id, 404);
        if ($objective->activities()->exists()) {
            throw ValidationException::withMessages(['objective' => 'Activities are planned under this objective.']);
        }
        $objective->delete();

        return response()->json(['message' => 'Objective deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'start_year' => ['required', 'integer', 'between:2000,2100'],
            'end_year' => ['required', 'integer', 'gte:start_year', 'lte:'.((int) $request->input('start_year') + 15)],
            'vision' => ['nullable', 'string', 'max:2000'],
            'mission' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function objective(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ]);
    }

    private function row(StrategicPlan $p): array
    {
        return [
            'id' => $p->id, 'title' => $p->title, 'start_year' => (int) $p->start_year, 'end_year' => (int) $p->end_year, 'vision' => $p->vision, 'mission' => $p->mission,
            'status' => $p->status, 'status_label' => StrategicPlan::STATUS_LABELS[$p->status] ?? $p->status,
            'objectives' => (int) ($p->objectives_count ?? 0), 'annual_plans' => (int) ($p->annual_plans_count ?? 0),
        ];
    }

    private function objectiveRow(StrategicObjective $o): array
    {
        return ['id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'description' => $o->description, 'sort_order' => (int) $o->sort_order, 'activities' => 0, 'progress' => 0];
    }

    private function ensureSees(Request $request): void
    {
        abort_unless($this->access->seesAll($request->user()) || $this->access->departments($request->user()) !== [], 403);
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($this->access->manages($request->user()), 403);
    }
}
