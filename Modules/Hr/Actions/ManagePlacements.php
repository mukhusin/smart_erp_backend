<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Internship and residency periods (HRMS manage_intern_period): end date = start + months - 1 day, year = start year;
 * the period is active from its start to its end date, then ended (by `contract:status`). Periods of one employee
 * cannot overlap, and the type must match the employee type.
 */
class ManagePlacements
{
    /** @param array{type: string, start_date: string, period: int, comment?: string|null} $data */
    public function add(Employee $employee, array $data, ?User $by): EmployeePlacement
    {
        $this->assertType($employee, $data['type']);
        [$start, $end] = $this->dates($data);
        $this->assertNoOverlap($employee, $start, $end);

        return $employee->placements()->create([
            'type' => $data['type'], 'year' => $start->year, 'period' => $data['period'], 'start_date' => $start, 'end_date' => $end,
            'status' => $this->statusFor($start, $end), 'comment' => $data['comment'] ?? null, 'created_by' => $by?->id,
        ]);
    }

    /** @param array{type: string, start_date: string, period: int, comment?: string|null} $data */
    public function update(EmployeePlacement $placement, array $data): EmployeePlacement
    {
        if ($placement->status === 'cancelled') {
            abort(422, 'A cancelled period cannot be changed.');
        }
        $this->assertType($placement->employee, $data['type']);
        [$start, $end] = $this->dates($data);
        $this->assertNoOverlap($placement->employee, $start, $end, $placement->id);

        $placement->update([
            'type' => $data['type'], 'year' => $start->year, 'period' => $data['period'], 'start_date' => $start, 'end_date' => $end,
            'status' => $this->statusFor($start, $end), 'comment' => $data['comment'] ?? null,
        ]);

        return $placement;
    }

    /** End early (left before the end date) or cancel a period that has not started. */
    public function stop(EmployeePlacement $placement, string $date, ?string $comment): EmployeePlacement
    {
        if (! in_array($placement->status, ['active', 'upcoming'], true)) {
            abort(422, 'Only an active or upcoming period can be ended.');
        }
        $date = CarbonImmutable::parse($date);
        if ($placement->status === 'upcoming' || $date->lt($placement->start_date)) {
            $placement->update(['status' => 'cancelled', 'comment' => $comment ?? $placement->comment]);
        } else {
            $placement->update(['status' => 'ended', 'end_date' => $date, 'comment' => $comment ?? $placement->comment]);
        }

        return $placement;
    }

    /** Start upcoming periods whose start date came; end active periods past their end date. */
    public function sync(Employee $employee): void
    {
        foreach ($employee->placements()->whereIn('status', ['upcoming', 'active'])->get() as $placement) {
            $status = $this->statusFor($placement->start_date->toImmutable(), $placement->end_date->toImmutable());
            if ($status !== $placement->status) {
                $placement->update(['status' => $status]);
            }
        }
    }

    public function statusFor(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $today = CarbonImmutable::today();

        return $start->gt($today) ? 'upcoming' : ($end->lt($today) ? 'ended' : 'active');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dates(array $data): array
    {
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();

        return [$start, $start->addMonthsNoOverflow((int) $data['period'])->subDay()];
    }

    private function assertType(Employee $employee, string $type): void
    {
        if ((EmployeePlacement::EMP_TYPES[$type] ?? null) !== $employee->emp_type) {
            $label = strtolower(EmployeePlacement::TYPES[$type] ?? $type);
            throw ValidationException::withMessages(['type' => "This employee is not on {$label}. Change the employee type first."]);
        }
    }

    private function assertNoOverlap(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?int $except = null): void
    {
        $overlap = $employee->placements()->where('status', '!=', 'cancelled')->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'It overlaps the period '.$overlap->start_date->format('d M Y').' – '.$overlap->end_date->format('d M Y').'.']);
        }
    }
}
