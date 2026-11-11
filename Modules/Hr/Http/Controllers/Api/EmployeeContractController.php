<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hr\Actions\ManageContracts;
use Modules\Hr\Http\Requests\ConfirmPasswordRequest;
use Modules\Hr\Http\Requests\ContractRequest;
use Modules\Hr\Http\Requests\EndContractRequest;
use Modules\Hr\Http\Resources\ContractResource;

/**
 * One active contract at a time. A renewal can be added in the last 90 days of the active contract; it waits
 * and starts by itself on its start date (`contract:status` runs every two hours).
 *
 * @tags HR: Contracts
 */
class EmployeeContractController extends Controller
{
    private const WITH = ['natureOfContract', 'updatedBy.employee', 'events.user.employee'];

    public function __construct(private readonly ManageContracts $contracts) {}

    /**
     * List an employee's contracts, newest first, with their history.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->contracts->sync($employee);

        return ContractResource::collection($employee->contracts()->with(self::WITH)->orderByDesc('start_date')->orderByDesc('id')->get());
    }

    /**
     * Give a contract.
     *
     * Starting today or earlier: it becomes the active contract and sets the employee type.
     * Starting later: it waits. With an active contract, this is a renewal: only in its last 90 days,
     * starting after it ends, and only one waiting contract at a time.
     */
    public function store(ContractRequest $request, Employee $employee): ContractResource
    {
        $contract = $this->contracts->give($employee, $request->validated(), $request->user());

        return new ContractResource($contract->load(self::WITH));
    }

    /**
     * Adjust the active or waiting contract.
     */
    public function update(ContractRequest $request, Employee $employee, EmployeeContract $contract): ContractResource
    {
        return new ContractResource($this->contracts->adjust($contract, $request->validated(), $request->user())->load(self::WITH));
    }

    /**
     * End the active or waiting contract.
     *
     * Reasons other than `ended` and `change_of_contract` mark the employee as terminated, cancel a waiting renewal
     * and deactivate the employee's user account on the leaving date. `meta.account`: `deactivated`, `scheduled`
     * (leaving date in the future), `kept` (the only company admin) or null (no account).
     */
    public function end(EndContractRequest $request, Employee $employee, EmployeeContract $contract)
    {
        $data = $request->validated();
        ['contract' => $ended, 'account' => $account] = $this->contracts->end($contract, $data['reason'], $data['date'], $data['comment'], $request->user());

        return (new ContractResource($ended->load(self::WITH)))->additional(['meta' => ['account' => $account]]);
    }

    /**
     * Remove a contract given by mistake.
     *
     * Only the latest contract, and only if payroll has not used it. The previous contract comes back
     * (active again if it has not expired), and so does the employee number it replaced.
     *
     * @response array{message: string, employee_no: string}
     */
    public function revert(ConfirmPasswordRequest $request, Employee $employee, EmployeeContract $contract): JsonResponse
    {
        $message = $this->contracts->revert($contract, $request->user());

        return response()->json(['message' => $message, 'employee_no' => $employee->refresh()->employee_no]);
    }
}
