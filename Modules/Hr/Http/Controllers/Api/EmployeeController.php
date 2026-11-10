<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hr\Actions\CreateEmployeeAccount;
use Modules\Hr\Actions\RegisterEmployee;
use Modules\Hr\Http\Requests\CreateAccountRequest;
use Modules\Hr\Http\Requests\StoreEmployeeRequest;
use Modules\Hr\Http\Resources\EmployeeResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags HR: Employees
 */
class EmployeeController extends Controller
{
    private const WITH = ['branch', 'department', 'section', 'jobPosition', 'user'];

    /**
     * List employees.
     */
    #[QueryParameter('filter[search]', description: 'Name, employee number, email or phone.', type: 'string')]
    #[QueryParameter('filter[branch_id]', type: 'integer')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[job_position_id]', type: 'integer')]
    #[QueryParameter('filter[employment_status]', type: 'string')]
    #[QueryParameter('filter[emp_type]', type: 'string')]
    #[QueryParameter('filter[has_account]', type: 'boolean')]
    #[QueryParameter('sort', description: '`employee_no`, `fname`, `employment_date`, `created_at`; prefix `-` for descending.', type: 'string', default: '-created_at')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(): AnonymousResourceCollection
    {
        $employees = QueryBuilder::for(Employee::with(self::WITH))
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($q) => $q
                    ->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('mname', 'like', "%{$v}%")
                    ->orWhere('employee_no', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")->orWhere('phone_number', 'like', "%{$v}%"))),
                AllowedFilter::exact('branch_id', 'company_branch_id'),
                AllowedFilter::callback('department_id', fn ($q, $v) => $q->where(fn ($q) => $q->where('department_id', $v)->orWhere('section_id', $v))),
                AllowedFilter::exact('job_position_id'),
                AllowedFilter::exact('employment_status'),
                AllowedFilter::exact('emp_type'),
                AllowedFilter::callback('has_account', fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('user_id') : $q->whereNull('user_id')),
            )
            ->allowedSorts('employee_no', 'fname', 'employment_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate(min((int) request('per_page', 25), 100))
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /**
     * Show an employee.
     */
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee->load(self::WITH));
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
     * @response array{data: array{employee_no: string}}
     */
    public function nextNumber(RegisterEmployee $register): JsonResponse
    {
        return response()->json(['data' => ['employee_no' => $register->nextNumber()]]);
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
