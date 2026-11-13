<?php

namespace Modules\Hr\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AdmittedEmployee;
use App\Models\AttendanceClaim;
use App\Models\AttendanceClaimReason;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceSyncDay;
use App\Models\AttendanceTransa;
use App\Models\Employee;
use App\Models\ExcuseOfDuty;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageAttendance;
use Modules\Hr\Actions\ManageAttendanceClaims;
use Modules\Hr\Attendance\BiotimeSource;
use Modules\Hr\Support\EmployeeScope;

/**
 * Biometric attendance (optional per company): device settings, punches, daily attendance, claims, excuse of duty and admitted staff.
 *
 * @tags Attendance
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly ManageAttendance $attendance, private readonly ManageAttendanceClaims $claims) {}

    /**
     * Attendance settings (the device password is never returned) and the last 14 sync days.
     *
     * @response array{data: array{source: string, sources: array<string, string>, host: string|null, port: int|null, database: string|null, username: string|null, has_password: bool, grace_minutes: int, sync_days: int, last_synced_at: string|null, last_error: string|null, tracked_employees: int, unlinked_punches: int, days: list<array{date: string, status: string, punches: int, attempts: int, error: string|null}>}}
     */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settingsRow(AttendanceSetting::current())]);
    }

    /**
     * Save the attendance settings. Leave the password empty to keep the saved one.
     *
     * @response array{data: array{source: string, sources: array<string, string>, host: string|null, port: int|null, database: string|null, username: string|null, has_password: bool, grace_minutes: int, sync_days: int, last_synced_at: string|null, last_error: string|null, tracked_employees: int, unlinked_punches: int, days: list<array{date: string, status: string, punches: int, attempts: int, error: string|null}>}}
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $d = $this->settingsData($request);
        $s = AttendanceSetting::query()->first() ?? new AttendanceSetting;
        $old = $s->connection ?? [];
        $s->fill(['source' => $d['source'], 'grace_minutes' => $d['grace_minutes'] ?? $s->grace_minutes ?? 0, 'sync_days' => $d['sync_days'] ?? $s->sync_days ?? 60, 'updated_by' => $request->user()->id,
            'connection' => $d['source'] === 'biotime' ? ['host' => $d['host'], 'port' => $d['port'] ?? 3306, 'database' => $d['database'], 'username' => $d['username'],
                'password' => ($d['password'] ?? '') !== '' ? $d['password'] : ($old['password'] ?? '')] : null])->save();

        return response()->json(['data' => $this->settingsRow($s->refresh())]);
    }

    /**
     * Try the device server connection with the given (or saved) details.
     *
     * @response array{data: array{ok: bool, message: string}}
     */
    public function testConnection(Request $request): JsonResponse
    {
        $d = $this->settingsData($request);
        $saved = AttendanceSetting::current()->connection ?? [];
        $source = new BiotimeSource(['host' => $d['host'], 'port' => $d['port'] ?? 3306, 'database' => $d['database'], 'username' => $d['username'],
            'password' => ($d['password'] ?? '') !== '' ? $d['password'] : ($saved['password'] ?? '')], 0);
        $ok = $source->reachable();

        return response()->json(['data' => ['ok' => $ok, 'message' => $ok ? 'Connected to the BioTime database.' : 'Could not connect. Check the address, database, user and password, and that this server can reach it.']]);
    }

    /**
     * Employees and their attendance tracking and device code.
     *
     * @response array{data: list<array{id: int, name: string, employee_no: string|null, department: string|null, attendance_code: string|null, device_code: string, has_attendance: bool, last_punch: string|null}>}
     */
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    public function employees(Request $request): JsonResponse
    {
        $f = $request->validate(['department_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $rows = app(EmployeeScope::class)->apply(Employee::with('department')->where('employment_status', '!=', 'terminated'), $request->user())
            ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where(fn ($q) => $q->where('department_id', $d)->orWhere('section_id', $d)))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('fname', 'like', "%$t%")->orWhere('lname', 'like', "%$t%")->orWhere('employee_no', 'like', "%$t%")))
            ->orderBy('fname')->limit(500)->get();
        $last = AttendancePunch::whereIn('employee_id', $rows->pluck('id'))->selectRaw('employee_id, max(punch_time) as last')->groupBy('employee_id')->pluck('last', 'employee_id');

        return response()->json(['data' => $rows->map(fn ($e) => [
            'id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name, 'attendance_code' => $e->attendance_code,
            'device_code' => ManageAttendance::codeOf($e), 'has_attendance' => (bool) $e->has_attendance, 'last_punch' => $last[$e->id] ?? null,
        ])->values()]);
    }

    /**
     * Turn attendance tracking on or off and set device codes for employees.
     *
     * @response array{data: array{updated: int, relinked: int}}
     */
    public function updateEmployees(Request $request): JsonResponse
    {
        $d = $request->validate([
            'employees' => ['required', 'array', 'min:1', 'max:1000'],
            'employees.*.id' => ['required', 'integer'],
            'employees.*.has_attendance' => ['required', 'boolean'],
            /** Code on the device; empty: the employee id (as HRMS). */
            'employees.*.attendance_code' => ['nullable', 'string', 'max:50'],
        ]);
        $scope = app(EmployeeScope::class);
        $updated = 0;
        foreach ($d['employees'] as $row) {
            $e = Employee::find($row['id']);
            if ($e && $scope->allows($request->user(), $e)) {
                $e->update(['has_attendance' => $row['has_attendance'], 'attendance_code' => ($row['attendance_code'] ?? '') !== '' ? trim($row['attendance_code']) : null]);
                $updated++;
            }
        }

        return response()->json(['data' => ['updated' => $updated, 'relinked' => $this->attendance->relink()]]);
    }

    /**
     * Attendance of a day for the employees the user can see, with counts by status.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, date: string, status: string|null, planned: string|null, planned_in: string|null, planned_out: string|null, punch_in: string|null, punch_out: string|null, late_minutes: int, early_minutes: int, overtime_minutes: int, worked_hours: float, planned_hours: float, lost_hours: float, lost_amount: float, accepted_hours: float, claimed: bool, area: string|null}>, meta: array{date: string, counts: array<string, int>, lost_amount: float}}
     */
    #[QueryParameter('date', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('status', type: 'string')]
    public function days(Request $request): JsonResponse
    {
        abort_unless($this->canView($request), 403);
        $f = $request->validate(['date' => ['nullable', 'date'], 'department_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:60']]);
        $date = Carbon::parse($f['date'] ?? now())->toDateString();
        $rows = AttendanceTransa::with('employee.department')->whereDate('transaction_date', $date)
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user())
                ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where(fn ($q) => $q->where('department_id', $d)->orWhere('section_id', $d))))
            ->get()->sortBy(fn ($r) => $r->employee?->full_name)->values();
        $counts = $rows->groupBy(fn ($r) => $r->status ?? '—')->map->count();
        if ($f['status'] ?? null) {
            $rows = $rows->where('status', $f['status'])->values();
        }

        return response()->json(['data' => $rows->map(fn ($r) => $this->dayRow($r))->values(), 'meta' => ['date' => $date, 'counts' => $counts, 'lost_amount' => round((float) $rows->sum('lost_amount'), 2)]]);
    }

    /**
     * An employee's attendance for a month, with totals and their punches.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, date: string, status: string|null, planned: string|null, planned_in: string|null, planned_out: string|null, punch_in: string|null, punch_out: string|null, late_minutes: int, early_minutes: int, overtime_minutes: int, worked_hours: float, planned_hours: float, lost_hours: float, lost_amount: float, accepted_hours: float, claimed: bool, area: string|null}>, punches: list<array{id: int, time: string, source: string, area: string|null, terminal: string|null, note: string|null}>, meta: array{month: string, totals: array{days_present: int, days_absent: int, late_minutes: int, overtime_minutes: int, worked_hours: float, lost_hours: float, lost_amount: float}}}
     */
    #[QueryParameter('month', type: 'string')]
    public function month(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->canView($request) && app(EmployeeScope::class)->allows($request->user(), $employee), 404);

        return response()->json($this->monthData($employee, $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m')));
    }

    /**
     * Work attendance out again for a period (after punches, rosters, leave or settings change).
     *
     * @response array{data: array{days: int, rows: int}}
     */
    public function compile(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'employee_id' => ['nullable', 'integer']]);
        abort_if(Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 62, 422, 'Work out at most 62 days at a time.');
        $ids = app(EmployeeScope::class)->apply(Employee::query(), $request->user())->when($d['employee_id'] ?? null, fn ($q, $e) => $q->whereKey($e))->pluck('id');

        return response()->json(['data' => $this->attendance->compile($d['from'], $d['to'], $ids)]);
    }

    /**
     * Queue the device sync for the missing days now (instead of waiting for the half-hourly run).
     *
     * @response array{data: array{queued: bool}}
     */
    public function syncNow(): JsonResponse
    {
        $this->ensureEnabled();
        Artisan::call('attendance:sync');

        return response()->json(['data' => ['queued' => true]]);
    }

    /**
     * Add a missed punch by hand (e.g. the device was down).
     *
     * @response array{data: array{added: int, skipped: int, unknown: list<string>, dates: list<string>}}
     */
    public function addPunch(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'punch_time' => ['required', 'date'], 'note' => ['required', 'string', 'max:255']], ['note.required' => 'Say why the punch is added by hand.']);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(app(EmployeeScope::class)->allows($request->user(), $e), 404);
        $r = $this->attendance->addPunches([['emp_code' => ManageAttendance::codeOf($e), 'punch_time' => $d['punch_time'], 'note' => $d['note']]], 'manual', $request->user()->id);
        $this->recompileDates($r['dates'], collect([$e->id]));

        return response()->json(['data' => $r], 201);
    }

    /**
     * Import missed punches (e.g. exported from a device that was offline) from a CSV file with columns emp_code, punch_time (Y-m-d H:i[:s]) and optionally terminal.
     *
     * @response array{data: array{added: int, skipped: int, unknown: list<string>, dates: list<string>}}
     */
    public function importPunches(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        abort_unless(in_array('emp_code', $header, true) && in_array('punch_time', $header, true), 422, 'The first row must name the columns emp_code and punch_time.');
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $r = array_combine($header, array_pad($line, count($header), null));
            if (! empty($r['emp_code']) && ! empty($r['punch_time']) && strtotime($r['punch_time'])) {
                $rows[] = ['emp_code' => $r['emp_code'], 'punch_time' => $r['punch_time'], 'terminal_alias' => $r['terminal'] ?? null];
            }
        }
        fclose($handle);
        $result = $this->attendance->addPunches($rows, 'csv', $request->user()->id);
        $this->recompileDates($result['dates']);

        return response()->json(['data' => $result], 201);
    }

    /**
     * Excuse of duty (sick note) and admitted staff records of a month.
     *
     * @response array{data: list<array{id: int, kind: string, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string}>}
     */
    #[QueryParameter('month', type: 'string')]
    public function absences(Request $request): JsonResponse
    {
        abort_unless($this->canView($request), 403);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $scope = fn ($q) => $q->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->with('employee.department');
        $rows = $scope(ExcuseOfDuty::query())->get()->map(fn ($r) => $this->absenceRow($r, 'excuse'))
            ->merge($scope(AdmittedEmployee::query())->get()->map(fn ($r) => $this->absenceRow($r, 'admitted')))->sortByDesc('start_date')->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Record an excuse of duty (kind = excuse) or an admission (kind = admitted); attendance of those days is worked out again.
     *
     * @response array{data: array{id: int, kind: string, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string}}
     */
    public function storeAbsence(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $d = $request->validate([
            'kind' => ['required', 'in:excuse,admitted'], 'employee_id' => ['required', 'integer'], 'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'hospital_name' => ['nullable', 'string', 'max:150'],
            'doctor_name' => ['nullable', 'string', 'max:150'], 'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $e = Employee::findOrFail($d['employee_id']);
        abort_unless(app(EmployeeScope::class)->allows($request->user(), $e), 404);
        $days = (int) Carbon::parse($d['start_date'])->diffInDays(Carbon::parse($d['end_date'])) + 1;
        $base = ['employee_id' => $e->id, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'number_of_days' => $days, 'hospital_name' => $d['hospital_name'] ?? null,
            'status' => 'active', 'created_by' => $request->user()->id];
        $row = $d['kind'] === 'excuse'
            ? ExcuseOfDuty::create($base + ['doctor_name' => $d['doctor_name'] ?? null, 'reason' => $d['reason'] ?? null])
            : AdmittedEmployee::create($base + ['admission_reason' => $d['reason'] ?? null]);
        $this->attendance->compile($d['start_date'], $d['end_date'], collect([$e->id]));

        return response()->json(['data' => $this->absenceRow($row->load('employee.department'), $d['kind'])], 201);
    }

    /** Remove an excuse of duty or admission record; those days are worked out again. */
    public function destroyAbsence(string $kind, int $id): Response
    {
        $row = ($kind === 'excuse' ? ExcuseOfDuty::query() : AdmittedEmployee::query())->findOrFail($id);
        abort_unless(app(EmployeeScope::class)->allows(request()->user(), $row->employee), 404);
        $row->delete();
        $this->attendance->compile($row->start_date, $row->end_date, collect([$row->employee_id]));

        return response()->noContent();
    }

    /**
     * Claim reasons.
     *
     * @response array{data: list<array{id: int, reason: string, description: string|null, is_active: bool}>}
     */
    public function reasons(): JsonResponse
    {
        return response()->json(['data' => AttendanceClaimReason::orderBy('reason')->get()->map(fn ($r) => ['id' => $r->id, 'reason' => $r->reason, 'description' => $r->description, 'is_active' => (bool) $r->is_active])]);
    }

    /**
     * Add a claim reason.
     *
     * @response array{data: array{id: int, reason: string, description: string|null, is_active: bool}}
     */
    public function storeReason(Request $request): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500']]);
        $r = AttendanceClaimReason::create($d + ['is_active' => true]);

        return response()->json(['data' => ['id' => $r->id, 'reason' => $r->reason, 'description' => $r->description, 'is_active' => true]], 201);
    }

    /**
     * Attendance claims of a month.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, lost_hours: float, claimed_hours: float, lost_amount: float, reason: string|null, description: string|null, status: string, waiting_for: string|null, comment: string|null}>}
     */
    #[QueryParameter('month', type: 'string')]
    public function claimsIndex(Request $request): JsonResponse
    {
        abort_unless($this->canView($request), 403);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $rows = AttendanceClaim::with(['employee.department', 'reason', 'workflow.steps'])->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->whereDate('claim_date', '>=', $from)->whereDate('claim_date', '<=', $from->copy()->endOfMonth())->latest('claim_date')->get();

        return response()->json(['data' => $rows->map(fn ($c) => $this->claimRow($c, true))->values()]);
    }

    /**
     * My attendance for a month (default this month).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, date: string, status: string|null, planned: string|null, planned_in: string|null, planned_out: string|null, punch_in: string|null, punch_out: string|null, late_minutes: int, early_minutes: int, overtime_minutes: int, worked_hours: float, planned_hours: float, lost_hours: float, lost_amount: float, accepted_hours: float, claimed: bool, area: string|null}>, punches: list<array{id: int, time: string, source: string, area: string|null, terminal: string|null, note: string|null}>, meta: array{month: string, totals: array{days_present: int, days_absent: int, late_minutes: int, overtime_minutes: int, worked_hours: float, lost_hours: float, lost_amount: float}}, claims: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, lost_hours: float, claimed_hours: float, lost_amount: float, reason: string|null, description: string|null, status: string, waiting_for: string|null, comment: string|null}>, reasons: list<array{id: int, reason: string}>, tracked: bool}
     */
    #[QueryParameter('month', type: 'string')]
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $data = $this->monthData($employee, $month);
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $data['claims'] = AttendanceClaim::with(['reason', 'workflow.steps'])->where('employee_id', $employee->id)->whereDate('claim_date', '>=', $from)->whereDate('claim_date', '<=', $from->copy()->endOfMonth())
            ->latest('claim_date')->get()->map(fn ($c) => $this->claimRow($c, false))->values();
        $data['reasons'] = AttendanceClaimReason::where('is_active', true)->orderBy('reason')->get(['id', 'reason']);
        $data['tracked'] = AttendanceSetting::current()->enabled() && $employee->has_attendance;

        return response()->json($data);
    }

    /**
     * Claim a day of my attendance (explain lost time). It goes through the approval workflow.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, lost_hours: float, claimed_hours: float, lost_amount: float, reason: string|null, description: string|null, status: string, waiting_for: string|null, comment: string|null}}
     */
    public function claimMine(Request $request, AttendanceTransa $day): JsonResponse
    {
        abort_unless($day->employee_id === $this->me($request)->id, 404);
        $this->ensureEnabled();
        $d = $request->validate(['claim_reason_id' => ['nullable', 'integer'], 'description' => ['required', 'string', 'max:1000']], ['description.required' => 'Explain what happened that day.']);

        return response()->json(['data' => $this->claimRow($this->claims->claim($day, $d, $request->user())->load(['reason', 'workflow.steps']), false)], 201);
    }

    private function monthData(Employee $employee, string $month): array
    {
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $rows = AttendanceTransa::with('employee.department')->where('employee_id', $employee->id)->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)
            ->orderBy('transaction_date')->get();
        $punches = AttendancePunch::where('employee_id', $employee->id)->whereBetween('punch_time', [$from, $to->copy()->endOfDay()])->orderBy('punch_time')->get();

        return [
            'data' => $rows->map(fn ($r) => $this->dayRow($r))->values(),
            'punches' => $punches->map(fn ($p) => ['id' => $p->id, 'time' => $p->punch_time->format('Y-m-d H:i'), 'source' => $p->source, 'area' => $p->area_alias, 'terminal' => $p->terminal_alias, 'note' => $p->note])->values(),
            'meta' => ['month' => $month, 'totals' => [
                'days_present' => $rows->whereIn('status', ['Present', 'Late', 'Early out', 'Late, early out', 'Not checked out'])->count(),
                'days_absent' => $rows->where('status', 'Absent')->count(), 'late_minutes' => (int) $rows->sum('punch_in_late_minutes'),
                'overtime_minutes' => (int) $rows->sum('overtime_minutes'), 'worked_hours' => round((float) $rows->sum('worked_hour'), 2),
                'lost_hours' => round((float) $rows->sum('lost_hour'), 2), 'lost_amount' => round((float) $rows->sum('lost_amount'), 2),
            ]],
        ];
    }

    private function recompileDates(array $dates, ?Collection $ids = null): void
    {
        foreach ($dates as $date) {
            $d = Carbon::parse($date);
            $this->attendance->compile($d->copy()->subDay(), $d, $ids);
        }
    }

    private function dayRow(AttendanceTransa $r): array
    {
        $e = $r->employee;
        $time = fn (?string $v) => $v ? substr($v, 11, 5) : null;

        return [
            'id' => $r->id, 'employee' => ['id' => $e?->id, 'name' => $e?->full_name, 'employee_no' => $e?->employee_no, 'department' => $e?->department?->name],
            'date' => $r->transaction_date->toDateString(), 'status' => $r->status, 'planned' => $r->planned_code ? "{$r->planned_code} · {$r->timetable_detail}" : $r->timetable_detail,
            'planned_in' => $time($r->attendance_in_time), 'planned_out' => $time($r->attendance_out_time), 'punch_in' => $r->punch_in_time ? substr($r->punch_in_time, 0, 16) : null,
            'punch_out' => $r->punch_out_time ? substr($r->punch_out_time, 0, 16) : null, 'late_minutes' => (int) $r->punch_in_late_minutes, 'early_minutes' => (int) $r->punch_out_late_minutes,
            'overtime_minutes' => (int) $r->overtime_minutes, 'worked_hours' => (float) $r->worked_hour, 'planned_hours' => (float) $r->worktime_hour, 'lost_hours' => (float) $r->lost_hour,
            'lost_amount' => (float) $r->lost_amount, 'accepted_hours' => (float) $r->accepted_hour, 'claimed' => (bool) $r->is_claimed, 'area' => $r->area_alias ?? $r->in_area_alias,
        ];
    }

    private function claimRow(AttendanceClaim $c, bool $withEmployee): array
    {
        $e = $c->employee;

        return [
            'id' => $c->id, 'employee' => $withEmployee && $e ? ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name] : null,
            'date' => $c->claim_date->toDateString(), 'lost_hours' => (float) $c->lost_hour, 'claimed_hours' => (float) $c->hr_accepted, 'lost_amount' => (float) $c->lost_amount,
            'reason' => $c->reason?->reason, 'description' => $c->description, 'status' => $c->claim_status,
            'waiting_for' => $c->claim_status === 'pending' ? $c->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'comment' => $c->comment,
        ];
    }

    private function absenceRow($r, string $kind): array
    {
        $e = $r->employee;

        return [
            'id' => $r->id, 'kind' => $kind, 'employee' => ['id' => $e?->id, 'name' => $e?->full_name, 'employee_no' => $e?->employee_no, 'department' => $e?->department?->name],
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(), 'number_of_days' => (int) $r->number_of_days, 'hospital_name' => $r->hospital_name,
            'doctor_name' => $kind === 'excuse' ? $r->doctor_name : null, 'reason' => $kind === 'excuse' ? $r->reason : $r->admission_reason, 'status' => $r->status,
        ];
    }

    private function settingsRow(AttendanceSetting $s): array
    {
        $c = $s->connection ?? [];

        return [
            'source' => $s->source ?? 'none', 'sources' => AttendanceSetting::SOURCES, 'host' => $c['host'] ?? null, 'port' => isset($c['port']) ? (int) $c['port'] : null,
            'database' => $c['database'] ?? null, 'username' => $c['username'] ?? null, 'has_password' => ! empty($c['password']), 'grace_minutes' => (int) $s->grace_minutes,
            'sync_days' => (int) ($s->sync_days ?: 60), 'last_synced_at' => $s->last_synced_at?->toIso8601String(), 'last_error' => $s->last_error,
            'tracked_employees' => Employee::where('has_attendance', true)->count(), 'unlinked_punches' => AttendancePunch::whereNull('employee_id')->count(),
            'days' => AttendanceSyncDay::orderByDesc('sync_date')->limit(14)->get()->map(fn ($d) => ['date' => $d->sync_date->toDateString(), 'status' => $d->status,
                'punches' => (int) $d->punch_count, 'attempts' => (int) $d->attempts, 'error' => $d->last_error])->values(),
        ];
    }

    private function settingsData(Request $request): array
    {
        return $request->validate([
            /** none (attendance not used) or biotime (biometric). */
            'source' => ['required', 'in:none,biotime'],
            'host' => ['required_if:source,biotime', 'nullable', 'string', 'max:150'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required_if:source,biotime', 'nullable', 'string', 'max:100'],
            'username' => ['required_if:source,biotime', 'nullable', 'string', 'max:100'],
            /** Empty: keep the saved password. */
            'password' => ['nullable', 'string', 'max:200'],
            /** Minutes late allowed before lateness counts. */
            'grace_minutes' => ['nullable', 'integer', 'between:0,120'],
            /** How many days back the sync checks for missing days. */
            'sync_days' => ['nullable', 'integer', 'between:1,120'],
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(AttendanceSetting::current()->enabled(), 422, 'Attendance is not used. Turn on biometric attendance in the attendance settings first.');
    }

    private function canView(Request $request): bool
    {
        $a = app(AccessService::class);

        return collect(['attendance.view', 'attendance.edit', 'attendance.working-hours.company', 'attendance.working-hours.department', 'attendance.working-hours.section'])
            ->contains(fn ($p) => $a->can($request->user(), $p));
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
