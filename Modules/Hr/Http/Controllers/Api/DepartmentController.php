<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Hr\Actions\AppointHead;
use Modules\Hr\Http\Requests\DepartmentRequest;
use Modules\Hr\Http\Resources\DepartmentResource;

/**
 * Departments, their sections and heads.
 *
 * @tags HR: Departments
 */
class DepartmentController extends Controller
{
    /**
     * Department structure: departments with their sections, heads and staff counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $departments = $this->withDetails(Department::whereNull('parent_id'))
            ->with(['sections' => fn ($q) => $this->withDetails($q)->orderByDesc('is_active')->orderBy('name')])
            ->orderByDesc('is_active')->orderBy('name')->get();

        return DepartmentResource::collection($departments);
    }

    /**
     * A department or section with its head history.
     */
    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($this->withDetails(Department::whereKey($department->id))
            ->with(['heads.employee', 'sections' => fn ($q) => $this->withDetails($q)->orderBy('name'),
                'positions' => fn ($q) => $q->withCount(['employees as staff_count' => fn ($e) => $e->active()])->orderByDesc('is_active')->orderBy('title')])
            ->firstOrFail());
    }

    /**
     * Add a department, or a section (with `parent_id`).
     */
    public function store(DepartmentRequest $request)
    {
        $department = Department::create($request->validated() + ['is_active' => true]);

        return $this->show($department)->response()->setStatusCode(201);
    }

    /**
     * Update a department or section.
     */
    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        $data = $request->validated();
        if (($data['parent_id'] ?? null) && $department->sections()->exists()) {
            abort(422, 'A department with sections cannot become a section.');
        }
        $department->update($data);

        return $this->show($department);
    }

    /**
     * Delete a department or section.
     *
     * Only when it has no employees (current or former) and no sections; otherwise deactivate it.
     */
    public function destroy(Department $department): Response
    {
        $used = Employee::withTrashed()->where(fn ($q) => $q->where('department_id', $department->id)->orWhere('section_id', $department->id))->exists();
        abort_if($used || $department->sections()->exists(), 422, 'It has employees or sections. Deactivate it instead.');
        $department->heads()->delete();
        $department->delete();

        return response()->noContent();
    }

    /**
     * Appoint the head of a department (or section).
     *
     * The previous head stays in the history. The new head's account gets the `hod` (department) or `section-head`
     * role and the previous head loses it, through the user-access log and approval workflow. `meta.warnings`
     * lists what HR should know (no account, role change waiting for approval...).
     */
    public function appointHead(Request $request, Department $department, AppointHead $heads)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $department->company_id)->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $warnings = $heads->appoint($department, Employee::findOrFail($data['employee_id']), $data['reason'] ?? null, $request->user());

        return $this->show($department)->additional(['meta' => ['warnings' => $warnings]]);
    }

    /**
     * Remove the head without appointing another.
     *
     * @response array{message: string, warnings: list<string>}
     */
    public function removeHead(Request $request, Department $department, AppointHead $heads): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['message' => 'Head removed.', 'warnings' => $heads->remove($department, $data['reason'] ?? null)]);
    }

    private function withDetails(Builder|Relation $query): Builder|Relation
    {
        return $query->with(['branch', 'headOfDepartment.employee.jobPosition'])
            ->withCount(['employees as staff_count' => fn ($q) => $q->active()])
            ->withCount(['sectionEmployees as section_staff_count' => fn ($q) => $q->active()]);
    }
}
