<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdmittedEmployee;
use App\Models\AnnualLeaveRoster;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\DailyAllowance;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLicence;
use App\Models\EmployeeTimetable;
use App\Models\ExcuseOfDuty;
use App\Models\Overtime;
use App\Models\Roster;
use App\Models\RosterChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Hr\Support\Heads;

/**
 * "My team": the start page of heads of department and section (and their deputies). Everything is limited to the
 * staff they head; amounts are shown for overtime and daily allowances only.
 *
 * @tags HR: My team
 */
class TeamController extends Controller
{
    public function __construct(private readonly EmployeeScope $scope, private readonly Heads $heads, private readonly AccessService $access) {}

    /**
     * Today, what is coming up, rosters and this month's overtime and allowances of the user's team.
     *
     * @response array{data: array{scope: list<string>, is_head: bool, staff: int, today: list<array{key: string, label: string, count: int, people: list<array{id: int, name: string, employee_no: string|null, detail: string|null}>}>, upcoming: list<array{key: string, label: string, count: int, people: list<array{id: int, name: string, employee_no: string|null, detail: string|null}>}>, rosters: list<array{id: int, title: string, kind: string, status: string, status_label: string, from_date: string, to_date: string, pending_changes: int, link: string}>, month: array{label: string, overtime_hours: float, overtime_amount: float, overtime_pending: int, allowance_items: float, allowance_amount: float, allowance_pending: int, lost_hours: float|null}, attendance: bool}}
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canBrowse($user) || $this->heads->isHead($user), 403);
        $today = Carbon::today();
        $staff = $this->scope->apply(Employee::query()->onDuty()->with('jobPosition'), $user)->get()->keyBy('id');
        $ids = $staff->keys();
        $person = fn ($e, ?string $detail = null) => ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'detail' => $detail];
        $group = fn (string $key, string $label, Collection $people) => ['key' => $key, 'label' => $label, 'count' => $people->count(), 'people' => $people->take(60)->values()->all()];
        $d = fn ($date) => Carbon::parse($date)->format('d M');

        $onDuty = EmployeeTimetable::whereIn('employee_id', $ids)->whereDate('day_date', $today)->where('source', EmployeeTimetable::ROSTER)->where('can_punch', true)->get()
            ->map(fn ($t) => $staff->has($t->employee_id) ? $person($staff[$t->employee_id], trim(($t->code ?? '').' '.substr((string) $t->start_time, 0, 5).'–'.substr((string) $t->end_time, 0, 5))) : null)->filter();
        $leave = EmpLeave::with('leaveType')->whereIn('employee_id', $ids)->whereIn('status', EmpLeave::TAKEN)->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->get();
        $sick = ExcuseOfDuty::whereIn('employee_id', $ids)->where('status', 'active')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->get();
        $admitted = AdmittedEmployee::whereIn('employee_id', $ids)->where('status', 'active')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->get();
        $attendance = AttendanceSetting::current()->enabled();
        $att = $attendance ? AttendanceTransa::whereIn('employee_id', $ids)->whereDate('transaction_date', $today)->get() : collect();
        $pick = fn ($rows, $fn) => $rows->map(fn ($r) => $staff->has($r->employee_id) ? $person($staff[$r->employee_id], $fn($r)) : null)->filter();

        $todayGroups = array_values(array_filter([
            $group('on_duty', 'On duty', $onDuty),
            $group('on_leave', 'On leave', $pick($leave, fn ($l) => ($l->leaveType?->name ?? 'Leave').', back '.$d($l->end_date->copy()->addDay()))),
            $group('sick', 'Sick (excuse of duty)', $pick($sick, fn ($s) => 'until '.$d($s->end_date))),
            $group('admitted', 'Admitted', $pick($admitted, fn ($a) => $a->hospital_name)),
            $attendance ? $group('late', 'Late', $pick($att->where('status', 'Late'), fn ($t) => $t->punch_in_late_minutes.' min')) : null,
            $attendance ? $group('absent', 'Absent', $pick($att->where('status', 'Absent'), fn ($t) => $t->planned_code)) : null,
        ]));

        $soon = $today->copy()->addDays(14);
        $contracts = EmployeeContract::with('natureOfContract')->whereIn('employee_id', $ids)->where('is_active', true)->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', $today->copy()->addDays(90))
            ->get()->filter(fn ($c) => ! $c->natureOfContract?->is_permanent)->sortBy('end_date');
        $licences = EmployeeLicence::with('professionalLicence')->whereIn('employee_id', $ids)->whereNotNull('end_date')->whereDate('end_date', '<=', $today->copy()->addDays(60))->get()->sortBy('end_date');
        $upcoming = [
            $group('leave_starting', 'Leave starting (2 weeks)', $pick(EmpLeave::with('leaveType')->whereIn('employee_id', $ids)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::PENDING])
                ->whereDate('start_date', '>', $today)->whereDate('start_date', '<=', $soon)->orderBy('start_date')->get(), fn ($l) => ($l->leaveType?->name ?? 'Leave').' from '.$d($l->start_date).($l->status === EmpLeave::PENDING ? ' (waiting)' : ''))),
            $group('contracts', 'Contracts ending (90 days)', $pick($contracts, fn ($c) => 'ends '.$d($c->end_date))),
            $group('licences', 'Licences expiring (60 days)', $pick($licences, fn ($l) => ($l->professionalLicence?->name ?? 'Licence').($l->end_date->lt($today) ? ' expired ' : ' expires ').$d($l->end_date))),
            $group('probation', 'Probation ending (30 days)', $staff->filter(fn ($e) => $e->probation_end && $e->probation_end->between($today, $today->copy()->addDays(30)) && $e->employment_status === 'probation')
                ->sortBy('probation_end')->map(fn ($e) => $person($e, 'ends '.$d($e->probation_end)))),
            $group('birthdays', 'Birthdays (7 days)', $staff->filter(fn ($e) => $e->date_of_birth && $this->birthdayWithin($e->date_of_birth, $today, 7))
                ->sortBy(fn ($e) => $this->nextBirthday($e->date_of_birth, $today))->map(fn ($e) => $person($e, $d($this->nextBirthday($e->date_of_birth, $today))))),
        ];

        $h = $this->heads->of($user);
        $rules = $this->scope->rules($user);
        $departments = $rules === null ? null : array_values(array_unique([...$h['departments'], ...($rules['departments'] ?? [])]));
        $sections = $rules === null ? null : array_values(array_unique([...$h['sections'], ...($rules['sections'] ?? [])]));
        $rosters = Roster::query()->whereIn('type', ['duty', 'annual_leave'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('type', 'duty')->whereDate('to_date', '>=', $today->copy()->startOfMonth())->whereDate('from_date', '<=', $today->copy()->addMonth()->endOfMonth()))
                ->orWhere(fn ($q) => $q->where('type', 'annual_leave')->where('roster_year', (string) $today->year)))
            ->when($departments !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('department_id', $departments)->orWhereIn('section_id', $sections ?: [0])))
            ->with(['department', 'section'])->orderBy('from_date')->limit(20)->get();
        $pendingChanges = RosterChangeRequest::whereIn('roster_id', $rosters->pluck('id'))->where('status', 'pending')->selectRaw('roster_id, count(*) n')->groupBy('roster_id')->pluck('n', 'roster_id');

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $ot = Overtime::whereIn('employee_id', $ids)->whereDate('date_of_task_done', '>=', $monthStart)->whereDate('date_of_task_done', '<=', $monthEnd);
        $al = DailyAllowance::whereIn('employee_id', $ids)->whereDate('start_date', '>=', $monthStart)->whereDate('start_date', '<=', $monthEnd);

        return response()->json(['data' => [
            'scope' => $this->heads->names($user) ?: ($rules === null ? ['Whole company'] : []),
            'is_head' => $this->heads->isHead($user),
            'staff' => $staff->count(),
            'today' => $todayGroups,
            'upcoming' => $upcoming,
            'rosters' => $rosters->map(fn ($r) => [
                'id' => $r->id, 'title' => $r->title, 'kind' => $r->type === 'duty' ? 'Duty roster' : 'Annual leave plan', 'status' => $r->status,
                'status_label' => Roster::STATUS_LABELS[$r->status] ?? $r->status, 'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString(),
                'pending_changes' => (int) ($pendingChanges[$r->id] ?? 0), 'link' => $r->type === 'duty' ? "/roster/rosters/{$r->id}" : "/leave/annual-rosters/{$r->id}",
            ])->values()->all(),
            'month' => [
                'label' => $today->format('F Y'),
                'overtime_hours' => round((float) (clone $ot)->where('status', 'approved')->sum('overtime_minutes') / 60, 1),
                'overtime_amount' => round((float) (clone $ot)->where('status', 'approved')->sum('amount'), 2),
                'overtime_pending' => (clone $ot)->where('status', 'pending')->count(),
                'allowance_items' => round((float) (clone $al)->where('status', 'approved')->sum('items'), 1),
                'allowance_amount' => round((float) (clone $al)->where('status', 'approved')->sum('amount'), 2),
                'allowance_pending' => (clone $al)->where('status', 'pending')->count(),
                'lost_hours' => $attendance ? round((float) AttendanceTransa::whereIn('employee_id', $ids)->whereDate('transaction_date', '>=', $monthStart)->whereDate('transaction_date', '<=', $today)->sum('lost_hour'), 1) : null,
            ],
            'attendance' => $attendance,
        ]]);
    }

    private function nextBirthday(Carbon $born, Carbon $today): Carbon
    {
        $next = $born->copy()->setYear($today->year);

        return $next->lt($today) ? $next->addYear() : $next;
    }

    private function birthdayWithin(Carbon $born, Carbon $today, int $days): bool
    {
        return $this->nextBirthday($born, $today)->lte($today->copy()->addDays($days));
    }
}
