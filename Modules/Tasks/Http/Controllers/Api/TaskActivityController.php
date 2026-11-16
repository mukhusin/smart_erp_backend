<?php

namespace Modules\Tasks\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ModuleAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Planning\Models\AnnualPlan;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Tasks
 */
class TaskActivityController extends Controller
{
    public function __construct(private readonly EmployeeScope $scope, private readonly AccessService $access) {}

    /**
     * Department activities for a year. Staff see their department's; heads the departments they manage; HR all.
     *
     * @response array{data: list<array{id: int, title: string, description: string|null, year: int, is_active: bool, department_id: int, department: string|null, tasks: int, open_tasks: int, progress: int, code: string|null, target: string|null, kpi: string|null, planned: bool, can_manage: bool}>, departments: list<array{id: int, name: string}>}
     */
    #[QueryParameter('year', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('active', description: 'Only active activities.', type: 'boolean')]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);
        $departments = $this->departments($user);
        $query = TaskActivity::with('department')->where('year', $year)
            ->withCount(['tasks', 'tasks as open_tasks_count' => fn ($q) => $q->whereIn('status', ['todo', 'in_progress', 'review', 'returned'])])
            ->withAvg(['tasks' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'progress')
            ->when($request->query('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true));
        if ($departments !== null) {
            $query->whereIn('department_id', array_merge($departments, array_filter([$user->employee?->department_id, $user->employee?->section_id])));
        }

        return response()->json([
            'data' => $query->orderBy('title')->get()->map(fn ($a) => $this->row($a, $user))->values(),
            'departments' => Department::query()->when($departments !== null, fn ($q) => $q->whereIn('id', $departments))->orderBy('name')->get(['id', 'name'])
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
        ]);
    }

    /** @response array{data: array{id: int, title: string, description: string|null, year: int, is_active: bool, department_id: int, department: string|null, tasks: int, open_tasks: int, progress: int, code: string|null, target: string|null, kpi: string|null, planned: bool, can_manage: bool}} */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->ensureManage($request->user(), (int) $data['department_id']);
        $this->ensureNotPlanned((int) $data['year']);
        $activity = TaskActivity::create($data + ['created_by' => $request->user()->id, 'is_active' => true]);

        return response()->json(['data' => $this->row($activity->load('department'), $request->user())], 201);
    }

    /** @response array{data: array{id: int, title: string, description: string|null, year: int, is_active: bool, department_id: int, department: string|null, tasks: int, open_tasks: int, progress: int, code: string|null, target: string|null, kpi: string|null, planned: bool, can_manage: bool}} */
    public function update(Request $request, TaskActivity $activity): JsonResponse
    {
        $this->ensureManage($request->user(), $activity->department_id);
        $this->ensureNotPlanned($activity->year, $activity);
        $data = $this->validated($request, true);
        isset($data['year']) && $this->ensureNotPlanned((int) $data['year']);
        isset($data['department_id']) && $this->ensureManage($request->user(), (int) $data['department_id']);
        $activity->update($data);

        return response()->json(['data' => $this->row($activity->load('department')->loadCount('tasks'), $request->user())]);
    }

    /** @response array{message: string} */
    public function destroy(Request $request, TaskActivity $activity): JsonResponse
    {
        $this->ensureManage($request->user(), $activity->department_id);
        $this->ensureNotPlanned($activity->year, $activity);
        if ($activity->tasks()->exists()) {
            throw ValidationException::withMessages(['activity' => 'This activity has tasks. Deactivate it instead.']);
        }
        $activity->delete();

        return response()->json(['message' => 'Activity deleted.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'year' => [$req, 'integer', 'between:2000,2100'],
            'department_id' => [$req, 'integer', Rule::exists('departments', 'id')->where('company_id', app(CompanyContext::class)->id())],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureNotPlanned(int $year, ?TaskActivity $activity = null): void
    {
        if ($activity?->annual_plan_id || (app(ModuleAccessService::class)->hasAccess('planning') && AnnualPlan::where('year', $year)->exists())) {
            throw ValidationException::withMessages(['activity' => "Activities for {$year} come from the annual plan; change them in Planning."]);
        }
    }

    private function departments(User $user): ?array
    {
        $rules = $this->scope->rules($user);

        return $rules === null ? null : array_values(array_unique(array_merge($rules['departments'], $rules['sections'])));
    }

    private function canManage(User $user, int $departmentId): bool
    {
        if (! $this->access->can($user, 'tasks.activities.manage')) {
            return false;
        }
        $departments = $this->departments($user);

        return $departments === null || in_array($departmentId, $departments, true);
    }

    private function ensureManage(User $user, int $departmentId): void
    {
        abort_unless($this->canManage($user, $departmentId), 403, 'You cannot manage activities of this department.');
    }

    private function row(TaskActivity $a, User $user): array
    {
        return [
            'id' => $a->id, 'title' => $a->title, 'description' => $a->description, 'year' => (int) $a->year, 'is_active' => (bool) $a->is_active,
            'department_id' => (int) $a->department_id, 'department' => $a->department?->name, 'tasks' => (int) ($a->tasks_count ?? 0),
            'open_tasks' => (int) ($a->open_tasks_count ?? 0), 'progress' => (int) round((float) ($a->tasks_avg_progress ?? 0)),
            'code' => $a->code, 'target' => $a->target, 'kpi' => $a->kpi, 'planned' => $a->annual_plan_id !== null,
            'can_manage' => $a->annual_plan_id === null && $this->canManage($user, (int) $a->department_id),
        ];
    }
}
