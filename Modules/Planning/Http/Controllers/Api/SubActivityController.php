<?php

namespace Modules\Planning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Http\Requests\SubActivityRequest;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\SubActivity;
use Modules\Planning\Support\PlanningAccess;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Planning
 */
class SubActivityController extends Controller
{
    public function __construct(private readonly PlanningAccess $access) {}

    /** @response array{data: array{id: int, code: string|null, title: string, output: string|null, quarters: string|null}} */
    public function store(SubActivityRequest $request, AnnualPlan $plan, Department $department, TaskActivity $activity): JsonResponse
    {
        $this->guard($request, $plan, $department, $activity);
        $sub = $activity->subActivities()->create($request->validated() + ['sort_order' => (int) $activity->subActivities()->max('sort_order') + 1]);

        return response()->json(['data' => $this->row($sub)], 201);
    }

    /** @response array{data: array{id: int, code: string|null, title: string, output: string|null, quarters: string|null}} */
    public function update(SubActivityRequest $request, AnnualPlan $plan, Department $department, TaskActivity $activity, SubActivity $sub): JsonResponse
    {
        $this->guard($request, $plan, $department, $activity);
        abort_unless($sub->task_activity_id === $activity->id, 404);
        $sub->update($request->validated());

        return response()->json(['data' => $this->row($sub)]);
    }

    /** @response array{message: string} */
    public function destroy(Request $request, AnnualPlan $plan, Department $department, TaskActivity $activity, SubActivity $sub): JsonResponse
    {
        $this->guard($request, $plan, $department, $activity);
        abort_unless($sub->task_activity_id === $activity->id, 404);
        if (DB::getSchemaBuilder()->hasTable('budget_items') && DB::table('budget_items')->where('sub_activity_id', $sub->id)->exists()) {
            throw ValidationException::withMessages(['sub_activity' => 'This sub-activity has budget items.']);
        }
        $sub->delete();

        return response()->json(['message' => 'Sub-activity removed.']);
    }

    private function guard(Request $request, AnnualPlan $plan, Department $department, TaskActivity $activity): void
    {
        abort_unless($this->access->canPlan($request->user(), $department->id), 403);
        abort_unless($activity->annual_plan_id === $plan->id && $activity->department_id === $department->id, 404);
        if ($plan->status === 'closed') {
            throw ValidationException::withMessages(['plan' => 'This annual plan is closed.']);
        }
    }

    private function row(SubActivity $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'title' => $s->title, 'output' => $s->output, 'quarters' => $s->quarters];
    }
}
