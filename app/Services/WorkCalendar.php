<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The planned working day of an employee, without attendance devices.
 *
 * Order: public holiday, then the working hours of the employee's section, department or the company default,
 * then the built-in Monday to Friday 08:00 to 17:00 with a one-hour break (the hours HRMS used when nothing was set).
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
        $holiday = $this->holidayOn($date);
        $schedule = $this->scheduleFor($employee);
        $hours = $schedule->dayOf($date->dayOfWeekIso);

        if ($holiday) {
            return new PlannedDay($date, PlannedDay::HOLIDAY, 'schedule', 'PH', $holiday->title);
        }
        if (! $hours) {
            return new PlannedDay($date, PlannedDay::OFF, 'schedule', 'OFF', 'Day off');
        }

        return PlannedDay::working($date, 'schedule', null, $schedule->name, $hours['start'], $hours['end'], $schedule->break_minutes);
    }

    /** @return Collection<int, PlannedDay> */
    public function days(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return collect(CarbonPeriod::create(Carbon::parse($from), Carbon::parse($to)))->map(fn ($d) => $this->day($employee, $d));
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
