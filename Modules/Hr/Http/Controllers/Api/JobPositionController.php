<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\JobPosition;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\JobPositionRequest;
use Modules\Hr\Http\Resources\JobPositionResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Job positions (titles) per department. Departments are per branch or company-wide, so the same title can exist
 * in each branch's department.
 *
 * @tags HR: Job positions
 */
class JobPositionController extends Controller
{
    private const WITH = ['department.branch'];

    /**
     * List job positions.
     */
    #[QueryParameter('filter[search]', type: 'string')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[branch_id]', description: 'Positions of departments of this branch (and company-wide ones).', type: 'integer')]
    #[QueryParameter('filter[is_active]', type: 'boolean')]
    #[QueryParameter('sort', description: '`title`, `staff_count`; prefix `-` for descending.', type: 'string', default: 'title')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $positions = QueryBuilder::for(JobPosition::with(self::WITH)->withCount(['employees as staff_count' => fn ($q) => $q->active()]))
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where('title', 'like', "%{$v}%")),
                AllowedFilter::exact('department_id'),
                AllowedFilter::callback('branch_id', fn ($q, $v) => $q->whereHas('department', fn ($d) => $d->where(fn ($d) => $d->whereNull('company_branch_id')->orWhere('company_branch_id', $v)))),
                AllowedFilter::callback('is_active', fn ($q, $v) => $q->where('is_active', filter_var($v, FILTER_VALIDATE_BOOLEAN))),
            )
            ->allowedSorts('title', 'staff_count')
            ->defaultSort('title')
            ->paginate(min((int) $request->input('per_page', 25), 100))
            ->withQueryString();

        return JobPositionResource::collection($positions);
    }

    /**
     * Add a job position.
     */
    public function store(JobPositionRequest $request): JobPositionResource
    {
        return new JobPositionResource(JobPosition::create($request->validated() + ['is_active' => true])->load(self::WITH));
    }

    /**
     * Update a job position.
     *
     * Employees keep the position when it moves to another department or is deactivated; deactivated positions are
     * no longer offered in forms.
     */
    public function update(JobPositionRequest $request, JobPosition $jobPosition): JobPositionResource
    {
        $jobPosition->update($request->validated());

        return new JobPositionResource($jobPosition->load(self::WITH)->loadCount(['employees as staff_count' => fn ($q) => $q->active()]));
    }

    /**
     * Delete a job position.
     *
     * Only when no employee (current or former) ever held it; otherwise deactivate it.
     */
    public function destroy(JobPosition $jobPosition): Response
    {
        abort_if(Employee::withTrashed()->where('job_position_id', $jobPosition->id)->exists(), 422, 'Employees hold or held this position. Deactivate it instead.');
        $jobPosition->delete();

        return response()->noContent();
    }
}
