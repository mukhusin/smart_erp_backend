<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Hr\Support\LeaveBalances;
use Modules\Hr\Support\LeaveDays;
use Modules\Hr\Support\LeavePeriods;

/**
 * Leave balances and day counting: HR and heads see the employees they can see; employees see their own (self service).
 *
 * @tags Leave: Balances
 */
class LeaveBalanceController extends Controller
{
    private function balances(): LeaveBalances
    {
        return app(LeaveBalances::class);
    }

    private function days(): LeaveDays
    {
        return app(LeaveDays::class);
    }

    /**
     * An employee's balance in each leave type for a leave period named by its start year (default: each type's current period), with HR adjustments.
     *
     * @response array{data: list<array{leave_type: array{id: int, name: string, code: string, kind: string, color: string|null, with_pay: bool, limit_per: string, notice_days: int, requires_document: bool}, leave_type_id: int, year: int, period_start: string, period_end: string, period_label: string, from_joining: bool, yearly: bool, entitled: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float, eligible: bool, reason: string|null}>, adjustments: list<array{id: int, leave_type: string, days: float, reason: string, by: string|null, at: string}>, meta: array{year: int, can_adjust: bool}}
     */
    #[QueryParameter('year', type: 'integer')]
    public function employee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeView($request, $employee);
        $year = $this->year($request);

        return response()->json([
            'data' => $this->rows($employee, $year, false),
            'adjustments' => LeaveBalanceAdjustment::with(['leaveType', 'creator.employee'])->where('employee_id', $employee->id)->where('leave_year', $year ?? app(LeavePeriods::class)->companyYear())->latest('id')->get()
                ->map(fn ($a) => ['id' => $a->id, 'leave_type' => $a->leaveType?->name, 'days' => $a->days, 'reason' => $a->reason, 'by' => $a->creator?->display_name, 'at' => $a->created_at->toIso8601String()])->values(),
            'meta' => ['year' => $year ?? app(LeavePeriods::class)->companyYear(), 'can_adjust' => app(AccessService::class)->can($request->user(), 'leave.balances.manage')],
        ]);
    }

    /**
     * Add days to (or take days from) an employee's balance for a year, with the reason (e.g. opening balance from HRMS).
     *
     * @response array{data: array{id: int, leave_type: string, days: float, reason: string, by: string|null, at: string}}
     */
    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $employee->company_id)->whereNull('deleted_at')->where('limit_per', 'year')],
            'leave_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            /** Positive adds days, negative takes days away. Half days allowed. */
            'days' => ['required', 'numeric', 'between:-366,366', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['leave_type_id.exists' => 'Choose a leave type with a yearly balance.', 'days.not_in' => 'Give the number of days to add or take away.']);
        $a = LeaveBalanceAdjustment::create($data + ['employee_id' => $employee->id, 'created_by' => $request->user()->id])->load('leaveType');

        return response()->json(['data' => ['id' => $a->id, 'leave_type' => $a->leaveType?->name, 'days' => $a->days, 'reason' => $a->reason, 'by' => $request->user()->display_name, 'at' => $a->created_at->toIso8601String()]], 201);
    }

    /** Remove an adjustment. */
    public function removeAdjustment(Employee $employee, LeaveBalanceAdjustment $adjustment): Response
    {
        abort_unless($adjustment->employee_id === $employee->id, 404);
        $adjustment->delete();

        return response()->noContent();
    }

    /**
     * My leave balances for a year (default this year): only the leave types I can take.
     *
     * @response array{data: list<array{leave_type: array{id: int, name: string, code: string, kind: string, color: string|null, with_pay: bool, limit_per: string, notice_days: int, requires_document: bool}, leave_type_id: int, year: int, period_start: string, period_end: string, period_label: string, from_joining: bool, yearly: bool, entitled: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float, eligible: bool, reason: string|null}>, meta: array{year: int}}
     */
    #[QueryParameter('year', type: 'integer')]
    public function mine(Request $request): JsonResponse
    {
        $year = $this->year($request);

        return response()->json(['data' => $this->rows($this->me($request), $year, true), 'meta' => ['year' => $year ?? app(LeavePeriods::class)->companyYear()]]);
    }

    /**
     * How many leave days a period takes for me (by the leave type's counting rule and my roster or working hours).
     * Give `end_date` to count the days, or `days` to find the last day.
     *
     * @response array{data: array{start_date: string, end_date: string, days: int, breakdown: list<array{date: string, counted: bool, label: string}>}}
     */
    #[QueryParameter('leave_type_id', type: 'integer', required: true)]
    #[QueryParameter('start_date', type: 'string', required: true, example: '2026-12-07')]
    #[QueryParameter('end_date', type: 'string', example: '2026-12-13')]
    #[QueryParameter('days', type: 'integer')]
    public function myDays(Request $request): JsonResponse
    {
        return $this->countFor($request, $this->me($request));
    }

    /**
     * How many leave days a period takes for an employee. Give `end_date` to count the days, or `days` to find the last day.
     *
     * @response array{data: array{start_date: string, end_date: string, days: int, breakdown: list<array{date: string, counted: bool, label: string}>}}
     */
    #[QueryParameter('leave_type_id', type: 'integer', required: true)]
    #[QueryParameter('start_date', type: 'string', required: true, example: '2026-12-07')]
    #[QueryParameter('end_date', type: 'string', example: '2026-12-13')]
    #[QueryParameter('days', type: 'integer')]
    public function employeeDays(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeView($request, $employee);

        return $this->countFor($request, $employee);
    }

    private function countFor(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $employee->company_id)->whereNull('deleted_at')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required_without:days', 'nullable', 'date', 'after_or_equal:start_date'],
            'days' => ['required_without:end_date', 'nullable', 'integer', 'min:1', 'max:366'],
        ]);
        $type = LeaveType::findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = ! empty($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : $this->days()->endDate($employee, $type, $start, (int) $data['days']);
        abort_if($start->diffInDays($end) > LeaveDays::MAX_SPAN, 422, 'The period is too long.');
        $breakdown = $this->days()->breakdown($employee, $type, $start, $end);

        return response()->json(['data' => [
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $breakdown->where('counted', true)->count(), 'breakdown' => $breakdown->values(),
        ]]);
    }

    private function rows(Employee $employee, ?int $year, bool $onlyEligible): array
    {
        $types = LeaveType::whereIn('id', collect($this->balances()->all($employee, $year))->pluck('leave_type_id'))->get()->keyBy('id');

        return collect($this->balances()->all($employee, $year, $onlyEligible))->map(fn ($b) => [
            'leave_type' => ($t = $types[$b['leave_type_id']]) ? ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'kind' => $t->kind, 'color' => $t->color, 'with_pay' => (bool) $t->with_pay, 'limit_per' => $t->limit_per, 'notice_days' => (int) $t->notice_days, 'requires_document' => (bool) $t->requires_document] : null,
        ] + $b)->values()->all();
    }

    /** The period asked for (named by its start year), or null for each leave type's current period. */
    private function year(Request $request): ?int
    {
        $year = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? null;

        return $year === null ? null : (int) $year;
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }

    private function authorizeView(Request $request, Employee $employee): void
    {
        $user = $request->user();
        $access = app(AccessService::class);
        $mayView = collect(['leave.view', 'leave.department.view', 'leave.balances.manage'])->contains(fn ($p) => $access->can($user, $p));
        abort_unless($mayView && app(EmployeeScope::class)->allows($user, $employee), 404);
    }
}
