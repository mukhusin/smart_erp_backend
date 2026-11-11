<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeExperience;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\ExperienceRequest;
use Modules\Hr\Http\Resources\ExperienceResource;

/**
 * Work experience before joining.
 *
 * @tags HR: Experience
 */
class EmployeeExperienceController extends Controller
{
    private const FIELDS = ['company_name', 'position', 'start_date', 'end_date', 'salary', 'description'];

    /**
     * List an employee's work experience, latest first.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return ExperienceResource::collection($employee->experiences()->orderByDesc('start_date')->get());
    }

    /**
     * Add work experience.
     */
    public function store(ExperienceRequest $request, Employee $employee): ExperienceResource
    {
        return new ExperienceResource($employee->experiences()->create($this->fields($request)));
    }

    /**
     * Update work experience.
     */
    public function update(ExperienceRequest $request, Employee $employee, EmployeeExperience $experience): ExperienceResource
    {
        $experience->update($this->fields($request));

        return new ExperienceResource($experience);
    }

    /**
     * Delete work experience.
     */
    public function destroy(Employee $employee, EmployeeExperience $experience): Response
    {
        $experience->delete();

        return response()->noContent();
    }

    private function fields(ExperienceRequest $request): array
    {
        return array_merge(array_fill_keys(self::FIELDS, null), $request->safe()->only(self::FIELDS));
    }
}
