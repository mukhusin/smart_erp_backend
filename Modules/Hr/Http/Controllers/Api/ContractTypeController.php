<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeContract;
use App\Models\NatureOfContract;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\ContractTypeRequest;
use Modules\Hr\Http\Resources\ContractTypeResource;

/**
 * Kinds of contract (full time, part time, internship...). The kind sets the employee type and what the
 * employee gets (annual leave, payroll, overtime...).
 *
 * @tags HR: Settings
 */
class ContractTypeController extends Controller
{
    /**
     * List contract types.
     */
    public function index(): AnonymousResourceCollection
    {
        return ContractTypeResource::collection(NatureOfContract::with('numberFormat')->orderByDesc('is_active')->orderBy('contract_name')->get());
    }

    /**
     * Add a contract type.
     */
    public function store(ContractTypeRequest $request): ContractTypeResource
    {
        return new ContractTypeResource(NatureOfContract::create($request->validated())->load('numberFormat'));
    }

    /**
     * Update a contract type.
     */
    public function update(ContractTypeRequest $request, NatureOfContract $contractType): ContractTypeResource
    {
        $contractType->update($request->validated());

        return new ContractTypeResource($contractType->load('numberFormat'));
    }

    /**
     * Delete a contract type.
     *
     * Types already used by a contract cannot be deleted; deactivate them instead.
     */
    public function destroy(NatureOfContract $contractType): Response
    {
        abort_if(EmployeeContract::withTrashed()->where('nature_of_contract_id', $contractType->id)->exists(), 422,
            'This contract type is used by contracts. Deactivate it instead.');
        $contractType->delete();

        return response()->noContent();
    }
}
