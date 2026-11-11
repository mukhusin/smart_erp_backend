<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\QualificationRequest;
use Modules\Hr\Http\Resources\QualificationResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags HR: Qualifications
 */
class EmployeeQualificationController extends Controller
{
    /**
     * List an employee's qualifications, latest first.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return QualificationResource::collection($employee->qualifications()->orderByDesc('end_year')->orderByDesc('id')->get());
    }

    /**
     * Add a qualification.
     */
    public function store(QualificationRequest $request, Employee $employee): QualificationResource
    {
        $qualification = $employee->qualifications()->make($this->fields($request));
        $this->saveFile($request, $employee, $qualification);
        $qualification->save();

        return new QualificationResource($qualification);
    }

    /**
     * Update a qualification.
     *
     * Sent as multipart form data; fields left out are cleared. The certificate is kept unless a new one is sent
     * or `remove_certificate` is true.
     */
    public function update(QualificationRequest $request, Employee $employee, EmployeeQualification $qualification): QualificationResource
    {
        $qualification->fill($this->fields($request));
        $this->saveFile($request, $employee, $qualification);
        $qualification->save();

        return new QualificationResource($qualification);
    }

    /**
     * Delete a qualification.
     */
    public function destroy(Employee $employee, EmployeeQualification $qualification): Response
    {
        $qualification->delete();
        EmployeeFiles::delete($qualification->certificate);

        return response()->noContent();
    }

    /**
     * Download the certificate.
     */
    public function file(Employee $employee, EmployeeQualification $qualification): StreamedResponse
    {
        return EmployeeFiles::response($qualification->certificate, $qualification->certificate_name);
    }

    private function fields(QualificationRequest $request): array
    {
        $keys = ['education_level', 'institution', 'program', 'date_of_start', 'date_of_end', 'end_year'];

        return array_merge(array_fill_keys($keys, null), $request->safe()->only($keys));
    }

    private function saveFile(QualificationRequest $request, Employee $employee, EmployeeQualification $qualification): void
    {
        if ($file = $request->file('certificate')) {
            $qualification->certificate = EmployeeFiles::replace($qualification->certificate, $file, $employee, 'qualifications');
            $qualification->certificate_name = $file->getClientOriginalName();
        } elseif ($request->boolean('remove_certificate')) {
            EmployeeFiles::delete($qualification->certificate);
            $qualification->certificate = $qualification->certificate_name = null;
        }
    }
}
