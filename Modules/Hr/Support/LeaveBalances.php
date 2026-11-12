<?php

namespace Modules\Hr\Support;

use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;

/**
 * Leave balances, worked out from the leave records (no stored balance to drift).
 *
 * For a leave type with a yearly limit: entitlement (the type's days) + days carried from last year (what was left,
 * up to the type's carry-over limit; as HRMS, left-over days are used first) + HR adjustments − days taken
 * (approved, on leave, reported back) − days requested and waiting, per leave period (see LeavePeriods: the company
 * leave year, or 12-month cycles from joining for new joiners' annual leave). Periods are named by their start year.
 * Carry-over is counted from the period the leave type was set up in; earlier balances come in as HR adjustments.
 * Types limited per request (e.g. compassionate) have no yearly balance: each request may take up to the days.
 */
class LeaveBalances
{
    public function __construct(private readonly LeavePeriods $periods) {}

    /** @var array<string, float> */
    private array $remaining = [];

    /** Why the employee cannot take this leave, or null. */
    public function ineligible(Employee $employee, LeaveType $type): ?string
    {
        if (! $type->is_active) {
            return 'This leave type is not in use.';
        }
        $gender = strtolower((string) $employee->gender);
        if (in_array($type->gender, ['male', 'female'], true) && $gender !== $type->gender) {
            return "Only for {$type->gender} employees.";
        }
        if ($type->employee_types && ! in_array($employee->emp_type, $type->employee_types, true)) {
            return 'Not for your employee type.';
        }
        if ($type->isAnnual() && ! $employee->has_annual_leave) {
            return 'Your contract does not give annual leave.';
        }

        return null;
    }

    /**
     * Balance in the period named `$year` (default: the period today falls in).
     *
     * @return array{leave_type_id: int, year: int, period_start: string, period_end: string, period_label: string, from_joining: bool, yearly: bool, entitled: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float, eligible: bool, reason: string|null}
     */
    public function for(Employee $employee, LeaveType $type, ?int $year = null): array
    {
        $year ??= $this->periods->current($employee, $type);
        $period = [
            'period_start' => $this->periods->start($employee, $type, $year)->toDateString(), 'period_end' => $this->periods->end($employee, $type, $year)->toDateString(),
            'period_label' => $this->periods->label($employee, $type, $year), 'from_joining' => $this->periods->onAnniversary($employee, $type),
        ];
        $reason = $this->ineligible($employee, $type);
        $taken = $this->taken($employee, $type, $year);
        $pending = $this->pending($employee, $type, $year);
        if (! $type->yearly()) {
            return [
                'leave_type_id' => $type->id, 'year' => $year, ...$period, 'yearly' => false, 'entitled' => (float) $type->max_days, 'carried_over' => 0.0,
                'adjustments' => 0.0, 'taken' => $taken, 'pending' => $pending, 'available' => $reason ? 0.0 : (float) $type->max_days,
                'eligible' => $reason === null, 'reason' => $reason,
            ];
        }
        $entitled = $this->entitled($employee, $type, $year);
        $carried = $this->carried($employee, $type, $year);
        $adjustments = $this->adjustments($employee, $type, $year);

        return [
            'leave_type_id' => $type->id, 'year' => $year, ...$period, 'yearly' => true, 'entitled' => $entitled, 'carried_over' => $carried, 'adjustments' => $adjustments,
            'taken' => $taken, 'pending' => $pending, 'available' => $reason ? 0.0 : max(0.0, $entitled + $carried + $adjustments - $taken - $pending),
            'eligible' => $reason === null, 'reason' => $reason,
        ];
    }

    /** @return list<array{leave_type_id: int, year: int, period_start: string, period_end: string, period_label: string, from_joining: bool, yearly: bool, entitled: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float, eligible: bool, reason: string|null}> */
    public function all(Employee $employee, ?int $year = null, bool $onlyEligible = false): array
    {
        return LeaveType::where('is_active', true)->orderByRaw("kind = 'annual' desc")->orderBy('name')->get()
            ->map(fn (LeaveType $t) => $this->for($employee, $t, $year))
            ->when($onlyEligible, fn ($rows) => $rows->where('eligible', true))
            ->values()->all();
    }

    private function entitled(Employee $employee, LeaveType $type, int $year): float
    {
        $joined = $this->periods->joined($employee);

        return $joined && $year < $this->periods->yearOf($employee, $type, $joined) ? 0.0 : (float) $type->max_days;
    }

    private function carried(Employee $employee, LeaveType $type, int $year): float
    {
        if (! $type->carry_over_days || $year - 1 < $this->firstYear($employee, $type)) {
            return 0.0;
        }

        return min((float) $type->carry_over_days, max(0.0, $this->remaining($employee, $type, $year - 1)));
    }

    private function remaining(Employee $employee, LeaveType $type, int $year): float
    {
        return $this->remaining["{$employee->id}|{$type->id}|{$year}"] ??= $this->entitled($employee, $type, $year)
            + $this->carried($employee, $type, $year) + $this->adjustments($employee, $type, $year) - $this->taken($employee, $type, $year);
    }

    private function firstYear(Employee $employee, LeaveType $type): int
    {
        $joined = $this->periods->joined($employee);
        $setUp = $this->periods->yearOf($employee, $type, $type->created_at ?? now());

        return max($setUp, $joined ? $this->periods->yearOf($employee, $type, $joined) : 0);
    }

    private function adjustments(Employee $employee, LeaveType $type, int $year): float
    {
        return (float) LeaveBalanceAdjustment::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year', $year)->sum('days');
    }

    private function taken(Employee $employee, LeaveType $type, int $year): float
    {
        return (float) EmpLeave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year', (string) $year)
            ->whereIn('status', EmpLeave::TAKEN)->sum('days');
    }

    private function pending(Employee $employee, LeaveType $type, int $year): float
    {
        return (float) EmpLeave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year', (string) $year)
            ->where('status', EmpLeave::PENDING)->sum('days');
    }

    public function forget(): void
    {
        $this->remaining = [];
        $this->periods->forget();
    }
}
