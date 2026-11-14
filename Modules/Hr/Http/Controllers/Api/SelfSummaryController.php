<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\ContractRenewal;
use App\Models\DailyAllowance;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\LeaveType;
use App\Models\Overtime;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use App\Services\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Hr\Support\LeaveBalances;

/**
 * @tags Self service: Dashboard
 */
class SelfSummaryController extends Controller
{
    public function __construct(private readonly AccessService $access) {}

    /**
     * My dashboard: who I am, my contract, my next days, and my leave, attendance, pay, loans, overtime and allowances.
     *
     * A section is null when its module is off for the company (attendance also when attendance is switched off or not
     * tracked for me). Amounts are mine only.
     *
     * @response array{data: array{employee: array{name: string, employee_no: string|null, position: string|null, department: string|null, photo_url: string|null, employed: string|null, years_of_service: float|null}, contract: array{type: string|null, start: string|null, end: string|null, days_left: int|null, progress: int|null, renewal_opens: string|null, renewal_open: bool, renewal_pending: bool, next_contract_starts: string|null}|null, days: list<array{date: string, kind: string, label: string, code: string|null, start: string|null, end: string|null, holiday: string|null, color: string|null}>, pending_requests: int, leave: array{annual: array{name: string, entitled: float, taken: float, pending: float, available: float}|null, other_available: list<array{name: string, available: float}>, pending: int, on_leave: array{type: string, until: string}|null, next: array{type: string, start: string, end: string, days: float}|null}|null, attendance: array{month: string, present: int, late: int, absent: int, lost_hours: float}|null, payroll: array{last: array{id: int, period: string, gross: float, net: float}|null, year: int, year_net: float, year_gross: float, trend: list<array{period: string, gross: float, net: float}>}|null, loans: array{active: int, remaining: float, monthly: float, advances_pending: int, advance_to_deduct: float}|null, overtime: array{month_hours: float, month_amount: float, pending: int}|null, allowances: array{month_amount: float, pending: int}|null}}
     */
    public function show(Request $request, WorkCalendar $calendar, LeaveBalances $balances): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::with(['department:id,name', 'jobPosition:id,title'])->where('user_id', $user->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $uses = fn (string $module) => $this->access->canUseModule($user, $module);

        $sections = [
            'leave' => $uses('leave') ? $this->leave($employee, $balances, $today) : null,
            'attendance' => $uses('attendance') && AttendanceSetting::current()->enabled() && $employee->has_attendance ? $this->attendance($employee, $monthStart, $today) : null,
            'payroll' => $uses('payroll') ? $this->payroll($employee, $today) : null,
            'loans' => $uses('loans') ? $this->loans($employee) : null,
            'overtime' => $uses('overtime') ? $this->overtime($employee, $monthStart, $today) : null,
            'allowances' => $uses('allowance') ? $this->allowances($employee, $monthStart, $today) : null,
        ];

        return response()->json(['data' => [
            'employee' => [
                'name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'position' => $employee->jobPosition?->title,
                'department' => $employee->department?->name,
                'photo_url' => $employee->profile_image ? route('self.profile.photo', [], false).'?v='.substr(md5($employee->profile_image), 0, 8) : null,
                'employed' => $employee->employment_date?->toDateString(),
                'years_of_service' => $employee->employment_date ? round($employee->employment_date->diffInDays($today) / 365.25, 1) : null,
            ],
            'contract' => $this->contract($employee, $today),
            'days' => $calendar->days($employee, $today, $today->addDays(6))->map(fn ($d) => collect($d->toArray())
                ->only(['date', 'kind', 'label', 'code', 'start', 'end', 'holiday', 'color'])->all())->values()->all(),
            'pending_requests' => $this->pending($employee, $user, $uses),
        ] + $sections]);
    }

    private function contract(Employee $employee, CarbonImmutable $today): ?array
    {
        $active = $employee->activeContract()->with('natureOfContract')->first();
        if (! $active) {
            return null;
        }
        $start = $active->start_date ? CarbonImmutable::parse($active->start_date) : null;
        $end = $active->end_date ? CarbonImmutable::parse($active->end_date) : null;
        $opens = $end?->subDays(O::RENEWAL_WINDOW_DAYS);
        $waiting = $employee->waitingContract()->first();

        return [
            'type' => $active->natureOfContract?->contract_name,
            'start' => $start?->toDateString(),
            'end' => $end?->toDateString(),
            'days_left' => $end ? max(0, (int) $today->diffInDays($end, false)) : null,
            'progress' => $start && $end && $end->gt($start) ? (int) round(min(1, max(0, $start->diffInDays($today) / $start->diffInDays($end))) * 100) : null,
            'renewal_opens' => $opens?->toDateString(),
            'renewal_open' => $opens !== null && $today->gte($opens) && ! $waiting,
            'renewal_pending' => $employee->renewals()->whereIn('status', ContractRenewal::OPEN)->exists(),
            'next_contract_starts' => $waiting?->start_date ? CarbonImmutable::parse($waiting->start_date)->toDateString() : null,
        ];
    }

    private function leave(Employee $employee, LeaveBalances $balances, CarbonImmutable $today): array
    {
        $rows = collect($balances->all($employee, null, true));
        $types = LeaveType::whereIn('id', $rows->pluck('leave_type_id'))->get()->keyBy('id');
        $annualRow = $rows->first(fn ($r) => ($types[$r['leave_type_id']]->kind ?? null) === 'annual');
        $current = EmpLeave::with('leaveType:id,name')->where('employee_id', $employee->id)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();
        $next = EmpLeave::with('leaveType:id,name')->where('employee_id', $employee->id)->where('status', EmpLeave::APPROVED)
            ->whereDate('start_date', '>', $today)->orderBy('start_date')->first();

        return [
            'annual' => $annualRow ? [
                'name' => $types[$annualRow['leave_type_id']]->name, 'entitled' => round($annualRow['entitled'] + $annualRow['carried_over'] + $annualRow['adjustments'], 1),
                'taken' => $annualRow['taken'], 'pending' => $annualRow['pending'], 'available' => $annualRow['available'],
            ] : null,
            'other_available' => $rows->reject(fn ($r) => $r === $annualRow)->filter(fn ($r) => $r['available'] > 0)
                ->map(fn ($r) => ['name' => $types[$r['leave_type_id']]->name, 'available' => $r['available']])->values()->take(4)->all(),
            'pending' => EmpLeave::where('employee_id', $employee->id)->where('status', EmpLeave::PENDING)->count(),
            'on_leave' => $current ? ['type' => $current->leaveType?->name ?? 'Leave', 'until' => CarbonImmutable::parse($current->end_date)->toDateString()] : null,
            'next' => $next ? ['type' => $next->leaveType?->name ?? 'Leave', 'start' => CarbonImmutable::parse($next->start_date)->toDateString(),
                'end' => CarbonImmutable::parse($next->end_date)->toDateString(), 'days' => (float) $next->days] : null,
        ];
    }

    private function attendance(Employee $employee, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $rows = AttendanceTransa::where('employee_id', $employee->id)->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $today)
            ->get(['status', 'punch_in_late_minutes', 'lost_hour']);

        return [
            'month' => $from->format('Y-m'),
            'present' => $rows->filter(fn ($r) => in_array($r->status, ['Present', 'Late', 'Early out', 'Late, early out'], true))->count(),
            'late' => $rows->filter(fn ($r) => (int) $r->punch_in_late_minutes > 0)->count(),
            'absent' => $rows->where('status', 'Absent')->count(),
            'lost_hours' => round((float) $rows->sum('lost_hour'), 1),
        ];
    }

    private function payroll(Employee $employee, CarbonImmutable $today): array
    {
        $slips = PayrollSheetDetail::with('payrollSheet:id,name,year,month,status')->where('employee_id', $employee->id)
            ->whereHas('payrollSheet', fn ($q) => $q->where('status', 'approved'))->get(['id', 'payroll_sheet_id', 'gross_salary', 'net_salary']);
        $last = $slips->sortByDesc(fn ($d) => sprintf('%04d%02d', $d->payrollSheet->year, $d->payrollSheet->month))->first();
        $year = $slips->filter(fn ($d) => (int) $d->payrollSheet->year === $today->year);

        return [
            'last' => $last ? ['id' => $last->id, 'period' => $last->payrollSheet->name, 'gross' => (float) $last->gross_salary, 'net' => (float) $last->net_salary] : null,
            'year' => $today->year,
            'year_net' => round((float) $year->sum('net_salary'), 2),
            'year_gross' => round((float) $year->sum('gross_salary'), 2),
            'trend' => $slips->sortBy(fn ($d) => sprintf('%04d%02d', $d->payrollSheet->year, $d->payrollSheet->month))->take(-6)->values()
                ->map(fn ($d) => ['period' => sprintf('%04d-%02d', $d->payrollSheet->year, $d->payrollSheet->month), 'gross' => (float) $d->gross_salary, 'net' => (float) $d->net_salary])->all(),
        ];
    }

    private function loans(Employee $employee): array
    {
        $loans = EmployeeLoan::where('employee_id', $employee->id)->whereIn('status', ['enabled', 'paused'])->get(['remain', 'instalment', 'status']);
        $advances = EmployeeMonthlyAdvance::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->get(['status', 'amount']);

        return [
            'active' => $loans->count(),
            'remaining' => round((float) $loans->sum('remain'), 2),
            'monthly' => round((float) $loans->where('status', 'enabled')->sum('instalment'), 2),
            'advances_pending' => $advances->where('status', 'pending')->count(),
            'advance_to_deduct' => round((float) $advances->where('status', 'approved')->sum('amount'), 2),
        ];
    }

    private function overtime(Employee $employee, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $month = Overtime::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('date_of_task_done', '>=', $from)->whereDate('date_of_task_done', '<=', $today->endOfMonth())->get(['overtime_minutes', 'amount']);

        return [
            'month_hours' => round((float) $month->sum('overtime_minutes') / 60, 1),
            'month_amount' => round((float) $month->sum('amount'), 2),
            'pending' => Overtime::where('employee_id', $employee->id)->where('status', 'pending')->count(),
        ];
    }

    private function allowances(Employee $employee, CarbonImmutable $from, CarbonImmutable $today): array
    {
        return [
            'month_amount' => round((float) DailyAllowance::where('employee_id', $employee->id)->where('status', 'approved')
                ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $today->endOfMonth())->sum('amount'), 2),
            'pending' => DailyAllowance::where('employee_id', $employee->id)->where('status', 'pending')->count(),
        ];
    }

    private function pending(Employee $employee, User $user, callable $uses): int
    {
        return $employee->renewals()->where('status', 'pending')->count()
            + ($uses('leave') ? EmpLeave::where('employee_id', $employee->id)->where('status', EmpLeave::PENDING)->count() : 0)
            + ($uses('overtime') ? Overtime::where('employee_id', $employee->id)->where('status', 'pending')->count() : 0)
            + ($uses('allowance') ? DailyAllowance::where('employee_id', $employee->id)->where('status', 'pending')->count() : 0)
            + ($uses('loans') ? EmployeeMonthlyAdvance::where('employee_id', $employee->id)->where('status', 'pending')->count() : 0);
    }
}
