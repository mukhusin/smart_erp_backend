<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractRenewal;
use App\Models\ContractRenewalFile;
use App\Models\Employee;
use App\Models\NatureOfContract;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Hr\Actions\ManageContracts;
use Modules\Hr\Actions\ManageRenewals;
use Modules\Hr\Http\Resources\ContractResource;
use Modules\Hr\Http\Resources\RenewalResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The logged-in employee's own contract and renewal requests (self-service).
 *
 * @tags Self service: Contract
 */
class SelfContractController extends Controller
{
    public function __construct(private readonly ManageRenewals $renewals, private readonly ManageContracts $contracts) {}

    /**
     * My contract: the active and waiting contract, my renewal requests, and whether I can request renewal now.
     *
     * @response array{data: array{contract: ContractResource|null, waiting_contract: ContractResource|null, can_request: bool, blocker: string|null, renewals: list<RenewalResource>, contract_types: list<array{id: int, name: string}>, agreement: string}}
     */
    public function show(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $this->contracts->sync($employee);
        $blocker = $this->renewals->blocker($employee, ContractRenewal::FROM_EMPLOYEE);
        $active = $employee->activeContract()->with('natureOfContract')->first();
        $waiting = $employee->waitingContract()->with('natureOfContract')->first();

        return response()->json(['data' => [
            'contract' => $active ? (new ContractResource($active))->resolve($request) : null,
            'waiting_contract' => $waiting ? (new ContractResource($waiting))->resolve($request) : null,
            'can_request' => $blocker === null,
            'blocker' => $blocker,
            'renewals' => RenewalResource::collection($employee->renewals()->with(['natureOfContract', 'files', 'workflow.steps', 'contract'])->get())->resolve($request),
            'contract_types' => NatureOfContract::where('is_active', true)->orderBy('contract_name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->contract_name]),
            'agreement' => ContractRenewal::AGREEMENT,
        ]]);
    }

    /**
     * Request renewal of my contract.
     *
     * Possible in the last 90 days of the active contract, unless HR has already started the renewal.
     * The request letter explains why; `agreed` confirms the statement in `agreement` (see GET /self/contract).
     * Sent as multipart form data with optional attachments (PDF, JPG or PNG, up to 5 MB each, at most 5).
     */
    public function store(Request $request): RenewalResource
    {
        $employee = $this->me($request);
        if (is_string($request->input('agreed'))) {
            $request->merge(['agreed' => filter_var($request->input('agreed'), FILTER_VALIDATE_BOOLEAN)]);
        }
        $data = $request->validate([
            /** Request letter: why I need my contract renewed. */
            'letter' => ['required', 'string', 'min:50', 'max:5000'],
            /** I agree to the statement in `agreement`. */
            'agreed' => ['accepted'],
            /** Contract type I would like (HR decides). */
            'nature_of_contract_id' => ['nullable', 'integer', Rule::exists('nature_of_contracts', 'id')->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)->whereNull('deleted_at')],
            /** Months I would like (HR decides). */
            'period' => ['nullable', 'integer', 'min:1', 'max:120'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', ...EmployeeFiles::DOCUMENT_RULES],
        ], [
            'letter.required' => 'Write your request letter.',
            'letter.min' => 'Your letter is too short; explain why you need the renewal (at least 50 characters).',
            'agreed.accepted' => 'Tick the agreement to send your request.',
        ]);

        $renewal = $this->renewals->start($employee, ContractRenewal::FROM_EMPLOYEE, collect($data)->except('files')->all(), $request->user(), $request->file('files', []));

        return new RenewalResource($renewal->load(['natureOfContract', 'files', 'workflow.steps', 'contract']));
    }

    /**
     * Withdraw my pending request.
     */
    public function withdraw(Request $request, ContractRenewal $renewal): RenewalResource
    {
        abort_unless($renewal->employee_id === $this->me($request)->id, 404);
        $this->renewals->withdraw($renewal, $request->user());

        return new RenewalResource($renewal->refresh()->load(['natureOfContract', 'files', 'workflow.steps', 'contract']));
    }

    /**
     * Download an attachment of my request.
     */
    public function file(Request $request, ContractRenewal $renewal, ContractRenewalFile $file): StreamedResponse
    {
        abort_unless($renewal->employee_id === $this->me($request)->id && $file->contract_renewal_id === $renewal->id, 404);

        return EmployeeFiles::response($file->file_path, $file->file_name);
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
