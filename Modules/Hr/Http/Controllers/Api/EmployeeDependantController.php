<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDependant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Http\Requests\DependantRequest;
use Modules\Hr\Http\Resources\DependantResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Family members and next of kin.
 *
 * @tags HR: Dependants
 */
class EmployeeDependantController extends Controller
{
    private const FIELDS = ['fullname', 'gender', 'relationship', 'date_of_birth', 'phone', 'email', 'address'];

    /**
     * List an employee's dependants, next of kin first.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return DependantResource::collection($employee->dependants()->orderByDesc('is_next_of_kin')->orderBy('id')->get());
    }

    /**
     * Add a dependant.
     */
    public function store(DependantRequest $request, Employee $employee): DependantResource
    {
        return new DependantResource($this->save($request, $employee, $employee->dependants()->make()));
    }

    /**
     * Update a dependant.
     *
     * Sent as multipart form data; fields left out are cleared. The birth certificate is kept unless a new one
     * is sent or `remove_birth_certificate` is true.
     */
    public function update(DependantRequest $request, Employee $employee, EmployeeDependant $dependant): DependantResource
    {
        return new DependantResource($this->save($request, $employee, $dependant));
    }

    /**
     * Delete a dependant.
     */
    public function destroy(Employee $employee, EmployeeDependant $dependant): Response
    {
        $dependant->delete();
        EmployeeFiles::delete($dependant->birth_certificate);

        return response()->noContent();
    }

    /**
     * Download the birth certificate.
     */
    public function file(Employee $employee, EmployeeDependant $dependant): StreamedResponse
    {
        return EmployeeFiles::response($dependant->birth_certificate, $dependant->birth_certificate_name);
    }

    private function save(DependantRequest $request, Employee $employee, EmployeeDependant $dependant): EmployeeDependant
    {
        $dependant->fill(array_merge(array_fill_keys(self::FIELDS, null), $request->safe()->only(self::FIELDS)));
        $dependant->is_next_of_kin = $request->boolean('is_next_of_kin');

        if ($file = $request->file('birth_certificate')) {
            $dependant->birth_certificate = EmployeeFiles::replace($dependant->birth_certificate, $file, $employee, 'dependants');
            $dependant->birth_certificate_name = $file->getClientOriginalName();
        } elseif ($request->boolean('remove_birth_certificate')) {
            EmployeeFiles::delete($dependant->birth_certificate);
            $dependant->birth_certificate = $dependant->birth_certificate_name = null;
        }

        DB::transaction(function () use ($employee, $dependant) {
            $dependant->save();
            if ($dependant->is_next_of_kin) {
                $employee->dependants()->whereKeyNot($dependant->id)->update(['is_next_of_kin' => false]);
            }
        });

        return $dependant;
    }
}
