<?php

namespace Modules\Hr\Http\Controllers\Api\Loans;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\LoanProvider;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Hr\Actions\ManageLoans;
use Modules\Hr\Http\Requests\LoanRequest;
use Modules\Hr\Support\EmployeeScope;

/**
 * Loan providers, employee loans and salary advances, deducted by payroll.
 *
 * @tags Loans & advances
 */
class LoansController extends Controller
{
    public function __construct(private readonly ManageLoans $loans) {}

    /**
     * Loan providers with their open loans.
     *
     * @response array{data: list<array{id: int, name: string, short_name: string|null, description: string|null, is_active: bool, open_loans: int, outstanding: float}>}
     */
    public function providers(): JsonResponse
    {
        return response()->json(['data' => LoanProvider::orderByDesc('is_active')->orderBy('name')->get()->map(fn ($p) => $this->provider($p))]);
    }

    /**
     * Add a loan provider (bank, SACCOS, the company).
     *
     * @response array{data: array{id: int, name: string, short_name: string|null, description: string|null, is_active: bool, open_loans: int, outstanding: float}}
     */
    public function storeProvider(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->provider(LoanProvider::create($this->providerData($request)))], 201);
    }

    /**
     * Update a loan provider.
     *
     * @response array{data: array{id: int, name: string, short_name: string|null, description: string|null, is_active: bool, open_loans: int, outstanding: float}}
     */
    public function updateProvider(Request $request, LoanProvider $provider): JsonResponse
    {
        $provider->update($this->providerData($request, $provider));

        return response()->json(['data' => $this->provider($provider)]);
    }

    /** Delete a provider without loans. */
    public function destroyProvider(LoanProvider $provider): Response
    {
        abort_if($provider->loans()->exists(), 422, 'Loans use this provider. Make it inactive instead.');
        $provider->delete();

        return response()->noContent();
    }

    /**
     * Employee loans of the employees the user can see.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}>, meta: array{outstanding: float, monthly: float}}
     */
    #[QueryParameter('status', type: 'string', description: 'open (default), pending, enabled, paused, disabled, all')]
    #[QueryParameter('loan_provider_id', type: 'integer')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:open,pending,enabled,paused,disabled,rejected,cancelled,all'], 'loan_provider_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer']]);
        $status = $f['status'] ?? 'open';
        $rows = EmployeeLoan::with(['employee.department', 'loanProvider', 'workflow.steps'])
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['pending', 'enabled', 'paused']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($f['loan_provider_id'] ?? null, fn ($q, $p) => $q->where('loan_provider_id', $p))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->latest('date_of_loan')->latest('id')->limit(500)->get();
        $repaying = $rows->where('status', 'enabled');

        return response()->json([
            'data' => $rows->map(fn ($l) => $this->loan($l, true))->values(),
            'meta' => ['outstanding' => round((float) $rows->whereIn('status', ['enabled', 'paused'])->sum('remain'), 2), 'monthly' => round((float) $repaying->sum(fn ($l) => min($l->instalment, $l->remain)), 2)],
        ]);
    }

    /**
     * A loan with its repayments and the instalments still to come.
     *
     * @response array{data: array{loan: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}, repayments: list<array{payroll: string|null, date: string|null, instalment: float, outstanding: float, status: string}>, schedule: list<array{month: string, instalment: float, remaining: float}>}}
     */
    public function show(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $this->inScope($request, $loan->employee);

        return response()->json(['data' => $this->loanDetail($loan)]);
    }

    /**
     * Record a loan for an employee (goes through the "Employee loan" workflow when set up).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}}
     */
    public function store(LoanRequest $request, Employee $employee): JsonResponse
    {
        $this->inScope($request, $employee);

        return response()->json(['data' => $this->loan($this->loans->createLoan($employee, $request->validated(), $request->user())->load(['employee.department', 'loanProvider', 'workflow.steps']), true)], 201);
    }

    /**
     * Change a loan (what remains = amount − repaid).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}}
     */
    public function update(LoanRequest $request, EmployeeLoan $loan): JsonResponse
    {
        $this->inScope($request, $loan->employee);

        return response()->json(['data' => $this->loan($this->loans->updateLoan($loan, $request->validated(), $request->user())->load(['employee.department', 'loanProvider', 'workflow.steps']), true)]);
    }

    /**
     * Pause (true) or resume (false) the repayments.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}}
     */
    public function pause(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $this->inScope($request, $loan->employee);
        $pause = (bool) $request->validate(['pause' => ['required', 'boolean']])['pause'];

        return response()->json(['data' => $this->loan($this->loans->pause($loan, $pause)->load(['employee.department', 'loanProvider', 'workflow.steps']), true)]);
    }

    /** Delete a loan with no repayments. */
    public function destroy(Request $request, EmployeeLoan $loan): Response
    {
        $this->inScope($request, $loan->employee);
        $this->loans->delete($loan);

        return response()->noContent();
    }

    /**
     * Salary advances of a month (by advance date).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}>, meta: array{month: string, total: float}}
     */
    #[QueryParameter('month', type: 'string', description: 'e.g. 2026-11; default this month')]
    public function advances(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $rows = EmployeeMonthlyAdvance::with(['employee.department', 'workflow.steps', 'detail.payrollSheet'])
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->whereDate('date_advanced', '>=', $from)->whereDate('date_advanced', '<=', $from->copy()->endOfMonth())->latest('date_advanced')->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->advance($a, true))->values(),
            'meta' => ['month' => $month, 'total' => round((float) $rows->whereIn('status', ['approved', 'deducted'])->sum('amount'), 2)]]);
    }

    /**
     * Give an employee a salary advance (goes through the "Salary advance" workflow when set up).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}}
     */
    public function storeAdvance(Request $request, Employee $employee): JsonResponse
    {
        $this->inScope($request, $employee);
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:9999999999'], 'date_advanced' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->advance($this->loans->createAdvance($employee, $d, $request->user())->load(['employee.department', 'workflow.steps']), true)], 201);
    }

    /**
     * Cancel an advance that is not on a payroll yet.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}}
     */
    public function cancelAdvance(Request $request, EmployeeMonthlyAdvance $advance): JsonResponse
    {
        $this->inScope($request, $advance->employee);

        return response()->json(['data' => $this->advance($this->loans->cancelAdvance($advance, $request->user())->load(['employee.department', 'workflow.steps']), true)]);
    }

    /**
     * My loans and salary advances.
     *
     * @response array{data: array{loans: list<array{loan: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, provider: array{id: int, name: string}|null, amount: float, instalment: float, returned: float, remain: float, date_of_loan: string, end_date: string|null, status: string, status_label: string, description: string|null, waiting_for: string|null, decision_comment: string|null, progress: int}, repayments: list<array{payroll: string|null, date: string|null, instalment: float, outstanding: float, status: string}>, schedule: list<array{month: string, instalment: float, remaining: float}>}>, advances: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}>, advance_limit: float}}
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $basic = (float) ($employee->activeContract()->value('basic_salary') ?? 0);

        return response()->json(['data' => [
            'loans' => EmployeeLoan::with(['loanProvider', 'workflow.steps'])->where('employee_id', $employee->id)->whereNotIn('status', ['rejected', 'cancelled'])->latest('date_of_loan')->get()
                ->map(fn ($l) => $this->loanDetail($l, false))->values(),
            'advances' => EmployeeMonthlyAdvance::with(['workflow.steps', 'detail.payrollSheet'])->where('employee_id', $employee->id)->latest('date_advanced')->limit(24)->get()
                ->map(fn ($a) => $this->advance($a, false))->values(),
            'advance_limit' => round($basic * ManageLoans::ADVANCE_MAX_PERCENT / 100, 2),
        ]]);
    }

    /**
     * Ask for a salary advance (up to half the basic salary, one at a time).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}}
     */
    public function requestAdvance(Request $request): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why you need the advance.']);

        return response()->json(['data' => $this->advance($this->loans->createAdvance($this->me($request), $d, $request->user(), 'employee')->load('workflow.steps'), false)], 201);
    }

    /**
     * Withdraw my advance request (or cancel it before payroll).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, amount: float, reason: string|null, date: string, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, payroll: string|null, can_cancel: bool}}
     */
    public function withdrawAdvance(Request $request, EmployeeMonthlyAdvance $advance): JsonResponse
    {
        abort_unless($advance->employee_id === $this->me($request)->id, 404);

        return response()->json(['data' => $this->advance($this->loans->cancelAdvance($advance, $request->user())->load('workflow.steps'), false)]);
    }

    private function loanDetail(EmployeeLoan $loan, bool $withEmployee = true): array
    {
        $loan->loadMissing(['employee.department', 'loanProvider', 'workflow.steps', 'histories.payrollSheet']);

        return [
            'loan' => $this->loan($loan, $withEmployee),
            'repayments' => $loan->histories->sortByDesc('id')->map(fn ($h) => ['payroll' => $h->payrollSheet?->name, 'date' => $h->repayment_date, 'instalment' => (float) $h->instalment,
                'outstanding' => (float) $h->outstanding_loan, 'status' => $h->status])->values(),
            'schedule' => $this->loans->schedule($loan),
        ];
    }

    private function loan(EmployeeLoan $l, bool $withEmployee): array
    {
        $e = $l->employee;

        return [
            'id' => $l->id, 'employee' => $withEmployee && $e ? ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name] : null,
            'provider' => $l->loanProvider ? ['id' => $l->loanProvider->id, 'name' => $l->loanProvider->name] : null,
            'amount' => (float) $l->amount, 'instalment' => (float) $l->instalment, 'returned' => (float) $l->returned, 'remain' => (float) $l->remain,
            'date_of_loan' => $l->date_of_loan->toDateString(), 'end_date' => $l->end_date?->toDateString(), 'status' => $l->status,
            'status_label' => EmployeeLoan::STATUS_LABELS[$l->status] ?? $l->status, 'description' => $l->description,
            'waiting_for' => $l->status === 'pending' ? $l->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $l->decision_comment,
            'progress' => $l->amount > 0 ? (int) round($l->returned / $l->amount * 100) : 0,
        ];
    }

    private function advance(EmployeeMonthlyAdvance $a, bool $withEmployee): array
    {
        $e = $a->employee;

        return [
            'id' => $a->id, 'employee' => $withEmployee && $e ? ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name] : null,
            'amount' => (float) $a->amount, 'reason' => $a->reason, 'date' => $a->date_advanced->toDateString(), 'status' => $a->status,
            'status_label' => EmployeeMonthlyAdvance::STATUS_LABELS[$a->status] ?? $a->status, 'source' => $a->source,
            'waiting_for' => $a->status === 'pending' ? $a->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $a->decision_comment,
            'payroll' => $a->detail?->payrollSheet?->name, 'can_cancel' => in_array($a->status, ['pending', 'approved'], true) && ! $a->payroll_sheet_detail_id,
        ];
    }

    private function provider(LoanProvider $p): array
    {
        $open = $p->loans()->whereIn('status', ['enabled', 'paused']);

        return ['id' => $p->id, 'name' => $p->name, 'short_name' => $p->short_name, 'description' => $p->description, 'is_active' => (bool) $p->is_active,
            'open_loans' => (clone $open)->count(), 'outstanding' => round((float) (clone $open)->sum('remain'), 2)];
    }

    private function providerData(Request $request, ?LoanProvider $provider = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('loan_providers', 'name')->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')->ignore($provider?->id)],
            'short_name' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string', 'max:255'], 'is_active' => ['boolean'],
        ], ['name.unique' => 'This provider is already on the list.']);

        return $d + ['is_active' => $provider?->is_active ?? true];
    }

    private function inScope(Request $request, ?Employee $employee): void
    {
        abort_unless($employee && app(EmployeeScope::class)->allows($request->user(), $employee), 404);
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
