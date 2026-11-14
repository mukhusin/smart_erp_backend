<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Actions\CreateEmployeeAccount;
use Modules\Hr\Actions\ManageContracts;
use Modules\Hr\Actions\ManagePlacements;
use Modules\Hr\Actions\RegisterEmployee;
use Modules\Hr\Actions\UpdateEmployee;
use Modules\Hr\Http\Requests\CreateAccountRequest;
use Modules\Hr\Http\Requests\ImportEmployeesRequest;
use Modules\Hr\Http\Requests\StoreEmployeeRequest;
use Modules\Hr\Http\Requests\UpdateEmployeeRequest;
use Modules\Hr\Http\Resources\EmployeeResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeScope;
use Modules\Hr\Support\EmployeeSheet;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags HR: Employees
 */
class EmployeeController extends Controller
{
    private const WITH = ['branch', 'department', 'section', 'jobPosition', 'user'];

    /**
     * List employees.
     *
     * Only employees the user may see: all with `hr.employees.view`, otherwise their branch, department(s) or
     * section. `meta.scope` describes it.
     */
    #[QueryParameter('filter[search]', description: 'Name, employee number, email or phone.', type: 'string')]
    #[QueryParameter('filter[branch_id]', type: 'integer')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[job_position_id]', type: 'integer')]
    #[QueryParameter('filter[employment_status]', type: 'string')]
    #[QueryParameter('filter[emp_type]', type: 'string')]
    #[QueryParameter('filter[has_account]', type: 'boolean')]
    #[QueryParameter('filter[view]', description: '`active`, `no_contract`, `contract_ending`, `invite_pending`, `licence_expiring` (same as the HR summary counts).', type: 'string')]
    #[QueryParameter('sort', description: '`employee_no`, `fname`, `employment_date`, `created_at`; prefix `-` for descending.', type: 'string', default: '-created_at')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(Request $request, EmployeeScope $scope): AnonymousResourceCollection
    {
        $employees = $this->query($request, $scope)
            ->paginate(min((int) request('per_page', 25), 100))
            ->withQueryString();

        return EmployeeResource::collection($employees)->additional(['meta' => ['scope' => $scope->describe($request->user())]]);
    }

    private function query(Request $request, EmployeeScope $scope): QueryBuilder
    {
        $base = Employee::with(self::WITH)->when(! in_array($request->input('filter.emp_type'), ['internship', 'resident'], true), fn ($q) => $q->staff());

        return QueryBuilder::for($scope->apply($base, $request->user()))
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($q) => $q
                    ->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('mname', 'like', "%{$v}%")
                    ->orWhere('employee_no', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")->orWhere('phone_number', 'like', "%{$v}%"))),
                AllowedFilter::exact('branch_id', 'company_branch_id'),
                AllowedFilter::callback('department_id', fn ($q, $v) => $q->where(fn ($q) => $q->where('department_id', $v)->orWhere('section_id', $v))),
                AllowedFilter::exact('job_position_id'),
                AllowedFilter::exact('employment_status'),
                AllowedFilter::exact('emp_type'),
                AllowedFilter::callback('view', fn ($q, $v) => match ($v) {
                    'active' => $q->active(),
                    'no_contract' => $q->withoutContract(),
                    'contract_ending' => $q->contractEnding(),
                    'invite_pending' => $q->invitePending(),
                    'licence_expiring' => $q->licenceExpiring(),
                    default => $q,
                }),
                AllowedFilter::callback('has_account', fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('user_id') : $q->whereNull('user_id')),
            )
            ->allowedSorts('employee_no', 'fname', 'employment_date', 'created_at')
            ->defaultSort('-created_at');
    }

    /**
     * Columns available for the employee export (salary only with `hr.contracts.manage` or `payroll.view`).
     */
    public function exportColumns(Request $request, EmployeeSheet $sheet): JsonResponse
    {
        $columns = $sheet->columnsFor(implode(',', array_keys(EmployeeSheet::EXPORT_COLUMNS)), $request->user());

        return response()->json([
            'data' => array_map(fn ($c) => ['value' => $c, 'label' => EmployeeSheet::EXPORT_COLUMNS[$c]], $columns),
            'default' => array_values(array_intersect(EmployeeSheet::DEFAULT_EXPORT, $columns)),
        ]);
    }

    /**
     * Export employees as CSV (opens in Excel). Same filters and scope as the list.
     */
    #[QueryParameter('filter[search]', description: 'Name, employee number, email or phone.', type: 'string')]
    #[QueryParameter('filter[branch_id]', type: 'integer')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[job_position_id]', type: 'integer')]
    #[QueryParameter('filter[employment_status]', type: 'string')]
    #[QueryParameter('filter[emp_type]', type: 'string')]
    #[QueryParameter('filter[has_account]', type: 'boolean')]
    #[QueryParameter('columns', description: 'Comma-separated keys from export-columns; default set when empty.', type: 'string')]
    public function export(Request $request, EmployeeScope $scope, EmployeeSheet $sheet): Response
    {
        $columns = $sheet->columnsFor($request->query('columns'), $request->user());
        $employees = $this->query($request, $scope)->with('activeContract')->reorder('employee_no')->get();

        return response($sheet->exportCsv($employees, $columns), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employees-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /**
     * Download the employee import template (CSV with one example row).
     */
    public function importTemplate(EmployeeSheet $sheet): Response
    {
        return response($sheet->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employee-import-template.csv"',
        ]);
    }

    /**
     * Import employees from CSV.
     *
     * Every row is checked first. With `commit` false only the check runs; with `commit` true employees are created
     * only when no row has errors (all or nothing). Branch, department, section and position are matched by name.
     * An empty `employee_no` takes the next number. `create_account` yes sends the invite.
     *
     * @response array{rows: list<array{line: int, employee_no: string|null, name: string, errors: list<string>}>, valid: int, invalid: int, created: int, invites_sent: int}
     */
    public function import(ImportEmployeesRequest $request, EmployeeSheet $sheet): JsonResponse
    {
        $result = $sheet->import($request->file('file'), $request->boolean('commit'));

        return response()->json($result, $request->boolean('commit') && $result['invalid'] ? 422 : 200);
    }

    /**
     * Show an employee, with the active and waiting contract and the number of records per profile section.
     */
    public function show(Employee $employee, ManageContracts $contracts, ManagePlacements $placements): EmployeeResource
    {
        $contracts->sync($employee);
        $placements->sync($employee);

        return new EmployeeResource($this->profile($employee));
    }

    /**
     * Update an employee's details.
     *
     * When the employee has a user account, its email and branch change too.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $update): EmployeeResource
    {
        $update->handle($employee, $request->employeeData(), $request->user()->username);

        return new EmployeeResource($this->profile($employee->refresh()));
    }

    /**
     * Upload the employee's photo.
     */
    public function uploadPhoto(Request $request, Employee $employee): EmployeeResource
    {
        $request->validate([
            /** JPG, PNG or WEBP, up to 2 MB. */
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $employee->update(['profile_image' => EmployeeFiles::replace($employee->profile_image, $request->file('photo'), $employee, 'photo')]);

        return new EmployeeResource($this->profile($employee));
    }

    /**
     * Remove the employee's photo.
     */
    public function deletePhoto(Employee $employee): EmployeeResource
    {
        EmployeeFiles::delete($employee->profile_image);
        $employee->update(['profile_image' => null]);

        return new EmployeeResource($this->profile($employee));
    }

    /**
     * The employee's photo.
     */
    public function photo(Employee $employee): StreamedResponse
    {
        return EmployeeFiles::response($employee->profile_image);
    }

    /** The employee with everything the profile shows. */
    public function profile(Employee $employee): Employee
    {
        return $employee->load([...self::WITH, 'activeContract.natureOfContract', 'waitingContract.natureOfContract', 'currentPlacement', 'bankAccount.bank', 'currentSupervisor.supervisor', 'patientId'])
            ->loadCount(['contracts', 'placements', 'qualifications', 'licences', 'experiences', 'documents', 'dependants',
                'dependants as next_of_kin_count' => fn ($q) => $q->where('is_next_of_kin', true), 'team']);
    }

    /**
     * Register an employee.
     *
     * With `create_account`, the employee also gets a user account (role "employee") and an email to set their
     * password. `meta.invite_sent` is false when that email could not be sent (resend it from the employee page).
     */
    public function store(StoreEmployeeRequest $request, RegisterEmployee $register)
    {
        ['employee' => $employee, 'invite_sent' => $sent] = $register->handle($request->employeeData());

        return (new EmployeeResource($employee->load(self::WITH)))
            ->additional(['meta' => ['invite_sent' => $sent]])
            ->response()->setStatusCode(201);
    }

    /**
     * Suggested next employee number.
     *
     * In the chosen number format, or the default format. `department_id` fills a department-code segment.
     * A company without its own formats gets HRMS-style numbers for `emp_type` (ST, PT, VOL, INT/.., RES/..).
     *
     * @response array{data: array{employee_no: string}}
     */
    #[QueryParameter('format_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('emp_type', type: 'string')]
    public function nextNumber(Request $request, RegisterEmployee $register): JsonResponse
    {
        $number = $register->nextNumber($request->integer('format_id') ?: null, $request->integer('department_id') ?: null, $request->query('emp_type') ?: null);

        return response()->json(['data' => ['employee_no' => $number]]);
    }

    /**
     * Create a user account for an employee.
     *
     * The account gets the "employee" role and an invite email. Other roles are given in Administration → Users.
     */
    public function createAccount(CreateAccountRequest $request, Employee $employee, CreateEmployeeAccount $accounts)
    {
        $accounts->create($employee, $request->validated('username'));
        $sent = $accounts->invite($employee->refresh());

        return (new EmployeeResource($employee->load(self::WITH)))
            ->additional(['meta' => ['invite_sent' => $sent]])
            ->response()->setStatusCode(201);
    }

    /**
     * Resend the account invite.
     *
     * Only while the employee has not set their password yet.
     *
     * @response array{message: string}
     */
    public function resendInvite(Employee $employee, CreateEmployeeAccount $accounts): JsonResponse
    {
        abort_if(! $employee->user, 422, 'This employee has no user account.');
        abort_if($employee->user->email_verified_at !== null, 422, 'This employee has already set their password.');
        abort_unless($accounts->invite($employee), 503, 'The invite email could not be sent. Try again later.');

        return response()->json(['message' => 'Invite sent.']);
    }
}
