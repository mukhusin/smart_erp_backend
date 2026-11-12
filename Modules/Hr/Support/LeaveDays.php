<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many leave days a period takes, by the leave type's counting rule and the employee's working calendar:
 * working days (roster or working hours; days off and public holidays are not counted), calendar days without
 * public holidays, or every calendar day.
 */
class LeaveDays
{
    public const MAX_SPAN = 400;

    public function __construct(private readonly WorkCalendar $calendar) {}

    public function counts(LeaveType $type, PlannedDay $day): bool
    {
        return match ($type->day_count) {
            'calendar' => true,
            'calendar_no_holidays' => $day->holiday === null || $day->isWorking(),
            default => $day->isWorking(),
        };
    }

    /** @return Collection<int, array{date: string, counted: bool, label: string}> */
    public function breakdown(Employee $employee, LeaveType $type, CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->calendar->days($employee, $from, $to, ignoreLeave: true)->map(fn (PlannedDay $d) => [
            'date' => $d->date->toDateString(), 'counted' => $this->counts($type, $d),
            'label' => $d->kind === PlannedDay::HOLIDAY ? ($d->holiday ?? $d->label) : ($d->isWorking() ? ($d->code ? "{$d->code} · {$d->label}" : $d->label) : $d->label),
        ]);
    }

    public function count(Employee $employee, LeaveType $type, CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return $this->breakdown($employee, $type, $from, $to)->where('counted', true)->count();
    }

    /** The last day of a leave of `$days` counted days starting on `$start`. */
    public function endDate(Employee $employee, LeaveType $type, CarbonInterface|string $start, int $days): Carbon
    {
        $start = Carbon::parse($start)->startOfDay();
        $chunk = max(31, $days * 2);
        $counted = 0;
        for ($from = $start->copy(); $from->diffInDays($start) < self::MAX_SPAN; $from->addDays($chunk)) {
            foreach ($this->calendar->days($employee, $from, $from->copy()->addDays($chunk - 1), ignoreLeave: true) as $day) {
                if ($this->counts($type, $day) && ++$counted >= $days) {
                    return $day->date->copy();
                }
            }
        }

        return $start->copy()->addDays(self::MAX_SPAN);
    }
}
