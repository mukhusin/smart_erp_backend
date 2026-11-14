<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\PublicHoliday;
use App\Models\Roster;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The planned working day of an employee, without attendance devices.
 *
 * Order: leave, then the duty on a published duty roster (also on public holidays), then public holidays, then the working
 * hours of the employee's section, department or the company default, then the built-in Monday to Friday 08:00 to 17:00
 * with a one-hour break (the hours HRMS used when nothing was set).
 */
class WorkCalendar
{
    public const BUILT_IN = [
        'name' => 'Standard (Mon to Fri, 08:00 to 17:00)',
        'days' => [
            ['day' => 1, 'start' => '08:00', 'end' => '17:00'], ['day' => 2, 'start' => '08:00', 'end' => '17:00'],
            ['day' => 3, 'start' => '08:00', 'end' => '17:00'], ['day' => 4, 'start' => '08:00', 'end' => '17:00'],
            ['day' => 5, 'start' => '08:00', 'end' => '17:00'],
        ],
        'break_minutes' => 60,
    ];

    private ?Collection $holidays = null;

    private ?Collection $schedules = null;

    /** @var array<int, int|null> */
    private array $departmentSchedule = [];

    public function day(Employee $employee, CarbonInterface|string $date): PlannedDay
    {
        $date = Carbon::parse($date)->startOfDay();

        return $this->resolve($employee, $date, $this->entries($employee, $date, $date)->get($date->toDateString(), collect()));
    }

    /** @return Collection<int, PlannedDay> */
    public function days(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to, bool $ignoreLeave = false): Collection
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $entries = $this->entries($employee, $from, $to);
        if ($ignoreLeave) {
            $entries = $entries->map(fn ($day) => $day->where('source', '!=', EmployeeTimetable::LEAVE)->values());
        }

        return collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $this->resolve($employee, $d, $entries->get($d->toDateString(), collect())));
    }

    /** Leave days and published roster duties of the employee, by date. */
    private function entries(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return EmployeeTimetable::where('employee_id', $employee->id)
            ->whereDate('day_date', '>=', $from->toDateString())->whereDate('day_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->where('source', '!=', EmployeeTimetable::ROSTER)
                ->orWhereHas('roster', fn ($r) => $r->where('status', Roster::PUBLISHED)))
            ->get()->groupBy(fn ($e) => $e->day_date->toDateString());
    }

    private function resolve(Employee $employee, CarbonInterface $date, Collection $entries): PlannedDay
    {
        $holiday = $this->holidayOn($date);
        if ($leave = $entries->firstWhere('source', EmployeeTimetable::LEAVE)) {
            return new PlannedDay($date, PlannedDay::LEAVE, 'leave', $leave->code, $leave->duty, holiday: $holiday?->title, color: $leave->color);
        }
        if ($excuse = $entries->firstWhere('source', EmployeeTimetable::EXCUSE)) {
            return new PlannedDay($date, PlannedDay::EXCUSED, 'excuse', $excuse->code, $excuse->duty, holiday: $holiday?->title, color: $excuse->color);
        }
        if ($duty = $entries->firstWhere('source', EmployeeTimetable::ROSTER)) {
            return $duty->can_punch && $duty->start_time && $duty->end_time
                ? PlannedDay::working($date, 'roster', $duty->code, $duty->duty, $duty->start_time, $duty->end_time, $duty->break_minutes, $holiday?->title, $duty->color, $duty->overtime)
                : new PlannedDay($date, PlannedDay::OFF, 'roster', $duty->code, $duty->duty, holiday: $holiday?->title, color: $duty->color);
        }
        $schedule = $this->scheduleFor($employee);
        $hours = $schedule->dayOf($date->dayOfWeekIso);

        if ($holiday) {
            return new PlannedDay($date, PlannedDay::HOLIDAY, 'schedule', 'PH', $holiday->title, holiday: $holiday->title);
        }
        if (! $hours) {
            return new PlannedDay($date, PlannedDay::OFF, 'schedule', 'OFF', 'Day off');
        }

        return PlannedDay::working($date, 'schedule', null, $schedule->name, $hours['start'], $hours['end'], $schedule->break_minutes);
    }

    public function workingDays(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return $this->days($employee, $from, $to)->filter->isWorking()->count();
    }

    public function holidayOn(CarbonInterface $date): ?PublicHoliday
    {
        return $this->holidays()->first(fn (PublicHoliday $h) => $h->fallsOn($date));
    }

    public function scheduleFor(Employee $employee): WorkSchedule
    {
        $id = $this->scheduleIdOf($employee->section_id) ?? $this->scheduleIdOf($employee->department_id);

        return ($id ? $this->schedules()->firstWhere('id', $id) : null) ?? $this->defaultSchedule();
    }

    public function defaultSchedule(): WorkSchedule
    {
        return $this->schedules()->firstWhere('is_default', true) ?? self::builtIn();
    }

    public static function builtIn(): WorkSchedule
    {
        return new WorkSchedule(self::BUILT_IN + ['is_default' => true]);
    }

    public function forget(): void
    {
        $this->holidays = $this->schedules = null;
        $this->departmentSchedule = [];
    }

    private function scheduleIdOf(?int $departmentId): ?int
    {
        if (! $departmentId) {
            return null;
        }

        return $this->departmentSchedule[$departmentId] ??= Department::withTrashed()->whereKey($departmentId)->value('work_schedule_id');
    }

    private function holidays(): Collection
    {
        return $this->holidays ??= PublicHoliday::where('is_active', true)->get();
    }

    private function schedules(): Collection
    {
        return $this->schedules ??= WorkSchedule::all();
    }
}
