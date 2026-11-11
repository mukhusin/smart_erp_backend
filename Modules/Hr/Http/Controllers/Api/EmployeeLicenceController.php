<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLicence;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\LicenceRequest;
use Modules\Hr\Http\Resources\LicenceResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags HR: Licences
 */
class EmployeeLicenceController extends Controller
{
    /**
     * List an employee's professional licences, latest expiry first.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return LicenceResource::collection($employee->licences()->with('professionalLicence')->orderByDesc('end_date')->get());
    }

    /**
     * Add a licence.
     */
    public function store(LicenceRequest $request, Employee $employee): LicenceResource
    {
        $licence = $employee->licences()->make($this->fields($request));
        $this->saveFile($request, $employee, $licence);
        $licence->save();

        return new LicenceResource($licence->load('professionalLicence'));
    }

    /**
     * Update a licence (renewal: change the dates and attach the new licence).
     *
     * Sent as multipart form data; fields left out are cleared. The file is kept unless a new one is sent
     * or `remove_file` is true.
     */
    public function update(LicenceRequest $request, Employee $employee, EmployeeLicence $licence): LicenceResource
    {
        $licence->fill($this->fields($request));
        $this->saveFile($request, $employee, $licence);
        $licence->save();

        return new LicenceResource($licence->load('professionalLicence'));
    }

    /**
     * Delete a licence.
     */
    public function destroy(Employee $employee, EmployeeLicence $licence): Response
    {
        $licence->delete();
        EmployeeFiles::delete($licence->file_path);

        return response()->noContent();
    }

    /**
     * Download the licence file.
     */
    public function file(Employee $employee, EmployeeLicence $licence): StreamedResponse
    {
        return EmployeeFiles::response($licence->file_path, $licence->file_name);
    }

    private function fields(LicenceRequest $request): array
    {
        $keys = ['professional_licence_id', 'licence_no', 'start_date', 'end_date'];

        return array_merge(array_fill_keys($keys, null), $request->safe()->only($keys));
    }

    private function saveFile(LicenceRequest $request, Employee $employee, EmployeeLicence $licence): void
    {
        if ($file = $request->file('file')) {
            $licence->file_path = EmployeeFiles::replace($licence->file_path, $file, $employee, 'licences');
            $licence->file_name = $file->getClientOriginalName();
        } elseif ($request->boolean('remove_file')) {
            EmployeeFiles::delete($licence->file_path);
            $licence->file_path = $licence->file_name = null;
        }
    }
}
