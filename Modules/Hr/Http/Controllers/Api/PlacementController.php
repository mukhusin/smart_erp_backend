<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePlacement;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Actions\ManagePlacements;
use Modules\Hr\Http\Requests\PlacementRequest;
use Modules\Hr\Http\Resources\PlacementResource;
use Modules\Hr\Support\EmployeeScope;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Internship and residency periods.
 *
 * @tags HR: Interns and residents
 */
class PlacementController extends Controller
{
    public function __construct(private readonly ManagePlacements $placements) {}

    /**
     * List internship and residency periods of employees the user may see.
     */
    #[QueryParameter('filter[search]', description: 'Name or employee number.', type: 'string')]
    #[QueryParameter('filter[type]', description: '`internship` or `residency`.', type: 'string')]
    #[QueryParameter('filter[status]', description: '`upcoming`, `active`, `ended`, `cancelled`.', type: 'string')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[job_position_id]', type: 'integer')]
    #[QueryParameter('filter[year]', type: 'integer')]
    #[QueryParameter('filter[ending_within]', description: 'Active periods ending within this many days.', type: 'integer')]
    #[QueryParameter('sort', description: '`start_date`, `end_date`, `year`; prefix `-` for descending.', type: 'string', default: 'end_date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(Request $request, EmployeeScope $scope): AnonymousResourceCollection
    {
        $employees = $scope->apply(Employee::query(), $request->user())->select('id');
        $rows = QueryBuilder::for(EmployeePlacement::with('employee.department', 'employee.jobPosition')->whereIn('employee_id', $employees))
            ->allowedFilters(
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('year'),
                AllowedFilter::callback('search', fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where(fn ($e) => $e
                    ->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%")))),
                AllowedFilter::callback('department_id', fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where(fn ($e) => $e->where('department_id', $v)->orWhere('section_id', $v)))),
                AllowedFilter::callback('job_position_id', fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('job_position_id', $v))),
                AllowedFilter::callback('ending_within', fn ($q, $v) => $q->where('status', 'active')->whereDate('end_date', '<=', now()->addDays((int) $v))),
            )
            ->allowedSorts('start_date', 'end_date', 'year')
            ->defaultSort('end_date')
            ->paginate(min((int) $request->input('per_page', 25), 100))
            ->withQueryString();

        return PlacementResource::collection($rows);
    }

    /**
     * An employee's internship or residency periods, newest first.
     */
    public function employeeIndex(Employee $employee): AnonymousResourceCollection
    {
        $this->placements->sync($employee);

        return PlacementResource::collection($employee->placements()->orderByDesc('start_date')->get());
    }

    /**
     * Add an internship or residency period.
     */
    public function store(PlacementRequest $request, Employee $employee): PlacementResource
    {
        return new PlacementResource($this->placements->add($employee, $request->validated(), $request->user()));
    }

    /**
     * Change a period.
     */
    public function update(PlacementRequest $request, Employee $employee, EmployeePlacement $placement): PlacementResource
    {
        return new PlacementResource($this->placements->update($placement, $request->validated()));
    }

    /**
     * End a period early, or cancel one that has not started.
     */
    public function stop(Request $request, Employee $employee, EmployeePlacement $placement): PlacementResource
    {
        $data = $request->validate(['date' => ['required', 'date'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return new PlacementResource($this->placements->stop($placement, $data['date'], $data['comment'] ?? null));
    }

    /**
     * Delete a period recorded by mistake.
     */
    public function destroy(Employee $employee, EmployeePlacement $placement): Response
    {
        $placement->delete();

        return response()->noContent();
    }
}
