<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\HrSetting;
use App\Models\LeaveType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Leave periods. A period is named by the year it starts in.
 *
 * - The company leave year starts in the month HR chooses (January by default, as HRMS).
 * - Annual leave of employees who joined on or after the "new joiners" date runs in 12-month cycles from their
 *   joining date (the ELRA leave cycle), when that setting is on. Employees who joined earlier keep the company year.
 *   The "new joiners" date defaults to the day annual leave was set up.
 */
class LeavePeriods
{
    private ?HrSetting $settings = null;

    public function settings(): HrSetting
    {
        return $this->settings ??= HrSetting::current();
    }

    public function joinersFrom(): ?Carbon
    {
        return $this->settings()->joiners_from?->copy()->startOfDay() ?? $this->joinersFromDefault();
    }

    /** The day annual leave was set up. */
    public function joinersFromDefault(): ?Carbon
    {
        $date = LeaveType::withTrashed()->where('kind', 'annual')->min('created_at');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    public function joined(Employee $employee): ?Carbon
    {
        $date = $employee->employment_date ?? $employee->contracts()->min('start_date');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /** Whether this leave type runs in cycles from the employee's joining date. */
    public function onAnniversary(Employee $employee, LeaveType $type): bool
    {
        if (! $type->isAnnual() || ! $this->settings()->annual_cycle_from_joining) {
            return false;
        }
        $joined = $this->joined($employee);
        $from = $this->joinersFrom();

        return $joined && $from && $joined->gte($from);
    }

    /** First day of the period named `$year`. */
    public function start(Employee $employee, LeaveType $type, int $year): Carbon
    {
        if ($this->onAnniversary($employee, $type)) {
            $joined = $this->joined($employee);

            return $joined->copy()->addYearsNoOverflow($year - $joined->year);
        }

        return Carbon::create($year, $this->settings()->leave_year_start_month ?: 1, 1)->startOfDay();
    }

    public function end(Employee $employee, LeaveType $type, int $year): Carbon
    {
        return $this->start($employee, $type, $year + 1)->subDay();
    }

    /** Name (start year) of the period a date falls in. */
    public function yearOf(Employee $employee, LeaveType $type, CarbonInterface|string $date): int
    {
        $date = Carbon::parse($date)->startOfDay();
        $year = $date->year;

        return $this->start($employee, $type, $year)->gt($date) ? $year - 1 : $year;
    }

    public function current(Employee $employee, LeaveType $type): int
    {
        return $this->yearOf($employee, $type, now());
    }

    public function label(Employee $employee, LeaveType $type, int $year): string
    {
        $start = $this->start($employee, $type, $year);
        $end = $this->end($employee, $type, $year);

        return $start->day === 1 && $start->month === 1 ? (string) $year : $start->format('d M Y').' – '.$end->format('d M Y');
    }

    /** The company leave year that today falls in. */
    public function companyYear(): int
    {
        $month = $this->settings()->leave_year_start_month ?: 1;

        return now()->month >= $month ? now()->year : now()->year - 1;
    }

    public function forget(): void
    {
        $this->settings = null;
    }
}
