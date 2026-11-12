<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractRenewal;
use App\Models\ContractRenewalFile;
use App\Models\Employee;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Hr\Actions\ManageRenewals;
use Modules\Hr\Http\Resources\RenewalResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeScope;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract renewals: started by HR or requested by the employee, approved through the "Contract renewal" workflow.
 * An employee has at most one open renewal, whoever started it.
 *
 * @tags HR: Contract renewals
 */
class RenewalController extends Controller
{
    private const WITH = ['employee.department', 'natureOfContract', 'files', 'requestedBy.employee', 'workflow.steps', 'contract'];

    public function __construct(private readonly ManageRenewals $renewals, private readonly EmployeeScope $scope) {}

    /**
     * List renewals of employees the user may see, newest first.
     */
    #[QueryParameter('filter[status]', description: '`pending`, `approved`, `completed`, `rejected`, `cancelled`; `open` = pending or approved.', type: 'string')]
    #[QueryParameter('filter[source]', description: '`hr` or `employee`.', type: 'string')]
    #[QueryParameter('filter[search]', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = QueryBuilder::for(ContractRenewal::with(self::WITH)->whereIn('employee_id', $this->scope->apply(Employee::query(), $request->user())->select('id')))
            ->allowedFilters(
                AllowedFilter::callback('status', fn ($q, $v) => $v === 'open' ? $q->whereIn('status', ContractRenewal::OPEN) : $q->where('status', $v)),
                AllowedFilter::exact('source'),
                AllowedFilter::callback('search', fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where(fn ($e) => $e
                    ->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%")))),
            )
            ->defaultSort('-id')
            ->paginate(min((int) $request->input('per_page', 25), 100))
            ->withQueryString();

        return RenewalResource::collection($rows);
    }

    /**
     * An employee's renewals, and whether HR can start one now (`meta.blocker` says why not).
     *
     * @response array{data: list<RenewalResource>, meta: array{blocker: string|null}}
     */
    public function employeeIndex(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => RenewalResource::collection($employee->renewals()->with(self::WITH)->get())->resolve(),
            'meta' => ['blocker' => $this->renewals->blocker($employee, ContractRenewal::FROM_HR)],
        ]);
    }

    /**
     * HR starts a renewal for the employee.
     *
     * It goes through the "Contract renewal" approval workflow (approved at once when none is set up). When approved,
     * the waiting contract is created with these terms, starting the day after the current contract ends.
     */
    public function store(Request $request, Employee $employee): RenewalResource
    {
        $data = $request->validate($this->termRules(required: true) + ['comment' => ['nullable', 'string', 'max:2000']]);
        $renewal = $this->renewals->start($employee, ContractRenewal::FROM_HR, $data, $request->user());

        return new RenewalResource($renewal->load(self::WITH));
    }

    /**
     * Create the contract of an approved renewal (when its terms were not complete, e.g. an employee request).
     */
    public function complete(Request $request, ContractRenewal $renewal): RenewalResource
    {
        $this->authorizeScope($request, $renewal);
        $data = $request->validate($this->termRules(required: true) + ['has_annual_leave' => ['sometimes', 'boolean'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return new RenewalResource($this->renewals->complete($renewal, $data, $request->user())->load(self::WITH));
    }

    /**
     * Withdraw a pending renewal (only who started it).
     */
    public function withdraw(Request $request, ContractRenewal $renewal): RenewalResource
    {
        $this->authorizeScope($request, $renewal);
        $this->renewals->withdraw($renewal, $request->user());

        return new RenewalResource($renewal->refresh()->load(self::WITH));
    }

    /**
     * Download an attachment.
     */
    public function file(Request $request, ContractRenewal $renewal, ContractRenewalFile $file): StreamedResponse
    {
        $this->authorizeScope($request, $renewal);
        abort_unless($file->contract_renewal_id === $renewal->id, 404);

        return EmployeeFiles::response($file->file_path, $file->file_name);
    }

    private function authorizeScope(Request $request, ContractRenewal $renewal): void
    {
        abort_unless($renewal->employee && $this->scope->allows($request->user(), $renewal->employee), 404);
    }

    private function termRules(bool $required): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            'nature_of_contract_id' => [$req, 'integer', Rule::exists('nature_of_contracts', 'id')->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)->whereNull('deleted_at')],
            /** Months. */
            'period' => [$req, 'integer', 'min:1', 'max:120'],
            'basic_salary' => [$req, 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
