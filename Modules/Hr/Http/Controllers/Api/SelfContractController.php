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
use Modules\Core\Support\RichText;
use Modules\Hr\Actions\ManageContracts;
use Modules\Hr\Actions\ManageRenewals;
use Modules\Hr\Http\Resources\ContractResource;
use Modules\Hr\Http\Resources\RenewalResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\RenewalLetter;
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
     * @response array{data: array{contract: ContractResource|null, waiting_contract: ContractResource|null, previous: list<ContractResource>, can_request: bool, blocker: string|null, renewals: list<RenewalResource>, contract_types: list<array{id: int, name: string}>, agreement: string, letter_templates: array<string, array{language: string, name: string, subject: string, salutation: string|null, closing: string, signature: list<string>}>}}
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
            'previous' => ContractResource::collection($employee->contracts()->with('natureOfContract')
                ->whereNotIn('id', array_filter([$active?->id, $waiting?->id]))->orderByDesc('start_date')->orderByDesc('id')->get())->resolve($request),
            'can_request' => $blocker === null,
            'blocker' => $blocker,
            'renewals' => RenewalResource::collection($employee->renewals()->with(['natureOfContract', 'files', 'workflow.steps', 'contract'])->get())->resolve($request),
            'contract_types' => NatureOfContract::where('is_active', true)->orderBy('contract_name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->contract_name]),
            'agreement' => ContractRenewal::AGREEMENT,
            'letter_templates' => RenewalLetter::templates($employee),
        ]]);
    }

    /**
     * Request renewal of my contract.
     *
     * Possible in the last 90 days of the active contract, unless HR has already started the renewal.
     * The employee writes the body of the letter; the subject line and signature come from `letter_templates`
     * (GET /self/contract) in the chosen language. `agreed` confirms the statement in `agreement`.
     * Sent as multipart form data with optional attachments (PDF, JPG or PNG, up to 5 MB each, at most 5).
     */
    public function store(Request $request): RenewalResource
    {
        $employee = $this->me($request);
        if (is_string($request->input('agreed'))) {
            $request->merge(['agreed' => filter_var($request->input('agreed'), FILTER_VALIDATE_BOOLEAN)]);
        }
        $data = $request->validate([
            /** Language of the letter template: sw (Kiswahili) or en (English). */
            'language' => ['required', Rule::in(array_keys(RenewalLetter::LANGUAGES))],
            /** Body of the request letter (HTML from the rich text editor): why I need my contract renewed. */
            'letter' => ['required', 'string', 'max:20000', function ($attribute, $value, $fail) {
                if (mb_strlen(RichText::text((string) $value)) < 200) {
                    $fail('Your letter is too short; explain why you need the renewal (at least 200 characters).');
                }
            }],
            /** I agree to the statement in `agreement`. */
            'agreed' => ['accepted'],
            /** Contract type I would like (approvers decide). Empty: the type of my current contract. */
            'nature_of_contract_id' => ['nullable', 'integer', Rule::exists('nature_of_contracts', 'id')->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)->whereNull('deleted_at')],
            /** Months I would like (HR decides). Empty: the months of my current contract. */
            'period' => ['nullable', 'integer', 'min:1', 'max:120'],
            /** Basic salary I propose (approvers decide). Empty: my current basic salary. */
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', ...EmployeeFiles::DOCUMENT_RULES],
        ], [
            'letter.required' => 'Write your request letter.',
            'language.required' => 'Choose the language of your letter.',
            'agreed.accepted' => 'Tick the agreement to send your request.',
        ]);

        $data['letter'] = RenewalLetter::compose($employee, $data['language'], $data['letter']);
        $data['letter_language'] = $data['language'];
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
