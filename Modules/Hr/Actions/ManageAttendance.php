<?php

namespace Modules\Hr\Actions;

use App\Models\AdmittedEmployee;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\ExcuseOfDuty;
use App\Models\PayrollDateSetting;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily attendance (HRMS compiled attendance), for employees whose attendance is tracked.
 *
 * Each day is matched to the planned day of the working calendar (roster duty, working hours, leave, holiday) and
 * to the punches around it (a night shift ends the next morning; the previous night's morning punch is not taken).
 * First punch = in, last punch = out. Late minutes after the start (less the grace minutes), early-out minutes before
 * the end, overtime minutes after the end; lost hours = late + early (a missing check-out or an absent day loses the
 * whole planned day). Lost amount = lost hours × (basic ÷ days in the payroll period ÷ planned hours). Approved claims
 * forgive lost hours. Leave, excuse of duty and admission are not absences; days off and holidays worked count as
 * overtime.
 */
class ManageAttendance
{
    private ?AttendanceSetting $settings = null;

    /** @var array<int, float> */
    private array $basic = [];

    public function settings(): AttendanceSetting
    {
        return $this->settings ??= AttendanceSetting::current();
    }

    /** The code the employee has on the device (HRMS used the employee id). */
    public static function codeOf(Employee $employee): string
    {
        return $employee->attendance_code ?: (string) $employee->id;
    }

    /** @return array{days: int, rows: int} */
    public function compile(CarbonInterface|string $from, CarbonInterface|string $to, ?Collection $employeeIds = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay()->min(now()->startOfDay());
        $rows = 0;
        if ($to->lt($from) || ! $this->settings()->enabled()) {
            return ['days' => 0, 'rows' => 0];
        }
        $employees = Employee::where('has_attendance', true)->onDuty()
            ->when($employeeIds, fn ($q) => $q->whereIn('id', $employeeIds))->get();
        foreach ($employees as $employee) {
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $this->compileDay($employee, $date);
                $rows++;
            }
        }

        return ['days' => (int) $from->diffInDays($to) + 1, 'rows' => $rows];
    }

    public function compileDay(Employee $employee, CarbonInterface $date): AttendanceTransa
    {
        $date = Carbon::parse($date)->startOfDay();
        $calendar = app(WorkCalendar::class);
        $days = $calendar->days($employee, $date->copy()->subDay(), $date);
        [$yesterday, $day] = [$days[0], $days[1]];
        $grace = (int) $this->settings()->grace_minutes;
        $now = now();

        if ($day->isWorking()) {
            $start = $day->startsAt();
            $end = $day->endsAt();
            $windowFrom = $start->copy()->subHours(3);
            $windowTo = $end->copy()->addHours(4);
        } else {
            $start = $end = null;
            $windowFrom = $date->copy();
            $windowTo = $date->copy()->endOfDay();
        }
        if ($yesterday->isWorking() && $yesterday->crossesMidnight) {
            $windowFrom = $windowFrom->max($yesterday->endsAt()->copy()->addMinutes(30));
        }
        $punches = AttendancePunch::where('employee_id', $employee->id)->whereBetween('punch_time', [$windowFrom, $windowTo])->orderBy('punch_time')->get();
        $in = $punches->first();
        $out = $punches->count() > 1 && $in->punch_time->diffInMinutes($punches->last()->punch_time) >= 5 ? $punches->last() : null;

        $basic = $this->basicSalary($employee);
        $perDay = $this->perDay($basic, $date);
        $planned = $day->isWorking() ? $day->hours : 0.0;
        $hourly = $planned > 0 ? $perDay / $planned : 0.0;
        $worked = $in && $out ? round($in->punch_time->diffInMinutes($out->punch_time) / 60, 2) : 0.0;

        $v = [
            'timetable_detail' => $day->label, 'planned_code' => $day->code, 'pass_day' => $day->crossesMidnight,
            'attendance_in_time' => $start?->format('Y-m-d H:i:s'), 'attendance_out_time' => $end?->format('Y-m-d H:i:s'),
            'punch_in_time' => $in?->punch_time->format('Y-m-d H:i:s'), 'punch_out_time' => $out?->punch_time->format('Y-m-d H:i:s'),
            'in_area_alias' => $in?->area_alias, 'area_alias' => $out?->area_alias, 'worktime_hour' => $planned, 'worked_hour' => $worked,
            'basic_salary' => $basic, 'amount_per_day' => round($perDay, 2), 'punch_in_late_minutes' => 0, 'punch_out_late_minutes' => 0,
            'punch_in_late_hour' => 0, 'punch_out_late_hour' => 0, 'overtime_minutes' => 0, 'overtime_hr' => 0, 'has_overtime' => false,
            'lost_hour' => 0, 'lost_amount' => 0, 'percent' => 0, 'deduct_leave' => false, 'description' => null,
        ];

        $excuse = ExcuseOfDuty::where('employee_id', $employee->id)->whereIn('status', ExcuseOfDuty::EXCUSED)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
        $official = app(ManageAbsences::class)->on($employee->id, $date);
        $admitted = AdmittedEmployee::where('employee_id', $employee->id)->where('status', '!=', 'cancelled')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();

        if ($day->kind === PlannedDay::LEAVE) {
            $v = $this->status($v, 'On leave', $day->label);
            $v['deduct_leave'] = $this->unpaidLeave($employee, $date);
        } elseif ($admitted) {
            $v = $this->status($v, 'Admitted', 'Admitted'.($admitted->hospital_name ? " ({$admitted->hospital_name})" : ''));
        } elseif ($excuse) {
            $v = $this->status($v, 'Excuse of duty', 'Excuse of duty'.($excuse->hospital_name ? " ({$excuse->hospital_name})" : ''));
        } elseif ($official) {
            $v = $this->status($v, 'Excused absence', 'Official absence'.($official->address ? " ({$official->address})" : ''));
            $v['description'] = $official->description;
        } elseif (! $day->isWorking()) {
            $label = $day->kind === PlannedDay::HOLIDAY ? ($day->holiday ?? 'Public holiday') : ($day->code && $day->source === 'roster' ? "{$day->code} · {$day->label}" : 'Day off');
            $v = $this->status($v, $label, $label);
            if ($worked > 0) {
                $minutes = (int) round($worked * 60);
                $v = array_merge($v, ['overtime_minutes' => $minutes, 'overtime_hr' => intdiv($minutes, 60), 'has_overtime' => true, 'description' => 'Worked on a day off']);
            }
        } elseif (! $in) {
            if ($now->lt($end->copy()->addMinutes($grace))) {
                $v = $this->status($v, 'Waiting', 'waiting for check in', 'waiting for check out');
            } else {
                $v = $this->status($v, 'Absent', 'absent');
                $v['lost_hour'] = $planned;
            }
        } else {
            $late = max(0, (int) $start->diffInMinutes($in->punch_time, false) - $grace);
            $v['punch_in_late_minutes'] = $late;
            $v['punch_in_late_hour'] = intdiv($late, 60);
            $v['punch_in_status'] = $late > 0 ? 'late' : ($in->punch_time->lt($start) ? 'early' : 'on time');
            if (! $out) {
                if ($now->lt($end)) {
                    $v['punch_out_status'] = 'waiting for check out';
                    $v['status'] = $late > 0 ? 'Late' : 'Present';
                    $v['lost_hour'] = round($late / 60, 2);
                } else {
                    $v['punch_out_status'] = 'not checked out';
                    $v['status'] = 'Not checked out';
                    $v['lost_hour'] = $planned;
                }
            } else {
                $early = max(0, (int) $out->punch_time->diffInMinutes($end, false));
                $over = max(0, (int) $end->diffInMinutes($out->punch_time, false));
                $v['punch_out_late_minutes'] = $early;
                $v['punch_out_late_hour'] = intdiv($early, 60);
                $v['punch_out_status'] = $early > 0 ? 'early out' : 'on time';
                $v['overtime_minutes'] = $over;
                $v['overtime_hr'] = intdiv($over, 60);
                $v['has_overtime'] = $over > 0;
                $v['lost_hour'] = round(min($planned, ($late + $early) / 60), 2);
                $v['status'] = match (true) {
                    $late > 0 && $early > 0 => 'Late, early out', $late > 0 => 'Late', $early > 0 => 'Early out', default => 'Present',
                };
            }
            $v['percent'] = $planned > 0 ? round(min(100, $worked / $planned * 100), 1) : 0;
        }

        $row = AttendanceTransa::where('employee_id', $employee->id)->whereDate('transaction_date', $date)->first() ?? new AttendanceTransa(['employee_id' => $employee->id, 'transaction_date' => $date->toDateString()]);
        $accepted = min((float) ($row->accepted_hour ?? 0), (float) $v['lost_hour']);
        $v['lost_hour'] = round(max(0, $v['lost_hour'] - $accepted), 2);
        $v['lost_amount'] = round($v['lost_hour'] * $hourly, 2);
        $v['actual_hour'] = round(max(0, $planned - $v['lost_hour']), 2);
        $v['amount_earned_per_day'] = $day->isWorking() ? round(max(0, $perDay - $v['lost_amount']), 2) : 0;
        $v['contract_id'] = $employee->activeContract()->value('id');
        $row->fill($v)->save();

        return $row->refresh();
    }

    private function status(array $v, string $status, string $in, ?string $out = null): array
    {
        return array_merge($v, ['status' => $status, 'punch_in_status' => $in, 'punch_out_status' => $out ?? $in]);
    }

    private function basicSalary(Employee $employee): float
    {
        return $this->basic[$employee->id] ??= (float) ($employee->activeContract()->value('basic_salary') ?? 0);
    }

    private function perDay(float $basic, Carbon $date): float
    {
        $settings = PayrollDateSetting::current();
        [$from, $to] = $settings->period($date->year, $date->month);
        if ($date->gt($to)) {
            [$from, $to] = $settings->period($date->copy()->addMonthNoOverflow()->year, $date->copy()->addMonthNoOverflow()->month);
        } elseif ($date->lt($from)) {
            [$from, $to] = $settings->period($date->copy()->subMonthNoOverflow()->year, $date->copy()->subMonthNoOverflow()->month);
        }
        $days = max(1, (int) $from->diffInDays($to) + 1);

        return $basic / $days;
    }

    private function unpaidLeave(Employee $employee, Carbon $date): bool
    {
        return EmpLeave::where('employee_id', $employee->id)->whereIn('status', EmpLeave::TAKEN)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->whereHas('leaveType', fn ($q) => $q->where('with_pay', false))->exists();
    }

    /**
     * Adds punches; links each to its employee by device code. Rows already there (same code and time) are skipped.
     *
     * @param  iterable<array{emp_code: string, punch_time: string, punch_state?: string|null, verify_type?: string|null, area_alias?: string|null, terminal_sn?: string|null, terminal_alias?: string|null, external_id?: int|null, note?: string|null}>  $rows
     * @return array{added: int, skipped: int, unknown: list<string>, dates: list<string>}
     */
    public function addPunches(iterable $rows, string $source, ?int $userId = null): array
    {
        $codes = Employee::query()->get(['id', 'attendance_code'])->mapWithKeys(fn ($e) => [($e->attendance_code ?: (string) $e->id) => $e->id]);
        $added = $skipped = 0;
        $unknown = [];
        $dates = [];
        foreach ($rows as $r) {
            $code = trim((string) $r['emp_code']);
            $time = Carbon::parse($r['punch_time']);
            $punch = AttendancePunch::firstOrNew(['emp_code' => $code, 'punch_time' => $time->format('Y-m-d H:i:s')]);
            if ($punch->exists) {
                $skipped++;

                continue;
            }
            $employeeId = $codes[$code] ?? null;
            if (! $employeeId) {
                $unknown[$code] = true;
            }
            $punch->fill([
                'employee_id' => $employeeId, 'punch_state' => $r['punch_state'] ?? null, 'verify_type' => $r['verify_type'] ?? null, 'area_alias' => $r['area_alias'] ?? null,
                'terminal_sn' => $r['terminal_sn'] ?? null, 'terminal_alias' => $r['terminal_alias'] ?? null, 'source' => $source, 'external_id' => $r['external_id'] ?? null,
                'note' => $r['note'] ?? null, 'created_by' => $userId,
            ])->save();
            $added++;
            $dates[$time->toDateString()] = true;
        }

        return ['added' => $added, 'skipped' => $skipped, 'unknown' => array_map('strval', array_keys($unknown)), 'dates' => array_keys($dates)];
    }

    /** Links punches whose device code now matches an employee (after codes are set). */
    public function relink(): int
    {
        $count = 0;
        foreach (Employee::query()->get(['id', 'attendance_code']) as $e) {
            $count += AttendancePunch::whereNull('employee_id')->where('emp_code', self::codeOf($e))->update(['employee_id' => $e->id]);
        }

        return $count;
    }
}
