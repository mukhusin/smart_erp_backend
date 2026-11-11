<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalLicence;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\LicenceTypeRequest;
use Modules\Hr\Http\Resources\LicenceTypeResource;

/**
 * Professional licences employees can hold (e.g. medical, nursing, pharmacy practising licences).
 *
 * @tags HR: Settings
 */
class LicenceTypeController extends Controller
{
    /**
     * List licence types.
     */
    public function index(): AnonymousResourceCollection
    {
        return LicenceTypeResource::collection(ProfessionalLicence::orderByDesc('is_active')->orderBy('name')->get());
    }

    /**
     * Add a licence type.
     */
    public function store(LicenceTypeRequest $request): LicenceTypeResource
    {
        return new LicenceTypeResource(ProfessionalLicence::create($request->validated()));
    }

    /**
     * Update a licence type.
     */
    public function update(LicenceTypeRequest $request, ProfessionalLicence $licenceType): LicenceTypeResource
    {
        $licenceType->update($request->validated());

        return new LicenceTypeResource($licenceType);
    }

    /**
     * Delete a licence type.
     *
     * Types held by employees cannot be deleted; deactivate them instead.
     */
    public function destroy(ProfessionalLicence $licenceType): Response
    {
        abort_if($licenceType->employeeLicences()->exists(), 422, 'Employees hold this licence. Deactivate it instead.');
        $licenceType->delete();

        return response()->noContent();
    }
}
