<?php

namespace Modules\Hr\Actions;

use App\Models\AllowanceActivityCount;
use App\Models\AllowanceCategory;
use App\Models\AttendanceTransa;
use App\Models\DailyAllowance;
use App\Models\DepartmentAllowance;
use App\Models\Employee;
use App\Models\User;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Workflow\AllowanceClaimProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Allowances (HRMS daily, night, theatre and monthly allowances), without needing attendance.
 *
 * A rate gives a category's amount to a department or section, optionally one job position; an employee gets the most
 * specific rate of each category (section + position, section, department + position, department), only when their
 * contract type gives allowances. Automatic categories are generated from the working calendar: per working day,
 * per night shift (a duty ending the next day), per public holiday worked, or per month. Leave days earn nothing.
 * Claimed categories (and per-case ones, e.g. theatre cases) are entered with a count and go through the
 * "Allowance claim" approval workflow.
 */
class ManageAllowances
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    /** Employees who get allowances (active contract whose type gives allowances). */
    public function eligible(Employee $employee): bool
    {
        if ($employee->employment_status === 'terminated') {
            return false;
        }

        return (bool) $employee->activeContract()->with('natureOfContract')->first()?->natureOfContract?->has_allowance;
    }

    /** The rate of a category that applies to the employee (most specific), or null. */
    public function rateFor(Employee $employee, AllowanceCategory $category, ?Collection $rates = null): ?DepartmentAllowance
    {
        $rates ??= DepartmentAllowance::where('category_id', $category->id)->where('is_active', true)->get();

        return $rates->where('category_id', $category->id)->where('is_active', true)
            ->filter(fn ($r) => (int) $r->department_id === (int) $employee->department_id
                && (! $r->section_id || (int) $r->section_id === (int) $employee->section_id)
                && (! $r->job_position_id || (int) $r->job_position_id === (int) $employee->job_position_id))
            ->sortByDesc(fn ($r) => ($r->section_id ? 2 : 0) + ($r->job_position_id ? 1 : 0))->first();
    }

    public function qualifies(AllowanceCategory $category, PlannedDay $day): bool
    {
        return match ($category->duration) {
            'day' => $day->isWorking(),
            'night' => $day->isWorking() && $day->crossesMidnight,
            'holiday' => $day->isWorking() && $day->holiday !== null,
            'month' => $day->date->day === 1,
            default => false,
        };
    }

    /**
     * Generates automatic allowances for the days from `$from` to `$to` (not after today). Days no longer earned
     * (e.g. the roster changed) lose their automatic allowance, unless already on a payroll.
     *
     * @return array{added: int, removed: int}
     */
    public function generate(Carbon|string $from, Carbon|string $to, ?Collection $employeeIds = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay()->min(now()->startOfDay());
        if ($to->lt($from)) {
            return ['added' => 0, 'removed' => 0];
        }
        $all = AllowanceCategory::where('is_active', true)->get();
        $categories = $all->filter->auto()->keyBy('id');
        $activities = $all->filter->activity()->keyBy('id');
        $rates = DepartmentAllowance::whereIn('category_id', $categories->keys()->merge($activities->keys()))->where('is_active', true)->get();
        $counts = AllowanceActivityCount::whereIn('allowance_category_id', $activities->keys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
            ->keyBy(fn ($c) => $c->allowance_category_id.'|'.$c->date->toDateString());
        $absent = $activities->contains('other_absence', true)
            ? AttendanceTransa::whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)->where('status', 'Absent')
                ->get(['employee_id', 'transaction_date'])->map(fn ($t) => $t->employee_id.'|'.$t->transaction_date->toDateString())->flip()
            : collect();
        $departments = $rates->pluck('department_id')->unique();
        $calendar = app(WorkCalendar::class);
        $added = $removed = 0;

        $employees = Employee::whereIn('department_id', $departments)->active()
            ->when($employeeIds, fn ($q) => $q->whereIn('id', $employeeIds))->get();
        foreach ($employees as $employee) {
            $due = [];
            $keep = [];
            if ($this->eligible($employee)) {
                $days = $calendar->days($employee, $from, $to);
                foreach ($categories as $category) {
                    $rate = $this->rateFor($employee, $category, $rates);
                    if (! $rate) {
                        continue;
                    }
                    foreach ($days as $day) {
                        if ($this->qualifies($category, $day)) {
                            $due[$category->id.'|'.$day->date->toDateString()] = [$category, $rate, $day, 1, $this->reason($category, $day)];
                        }
                    }
                }
                foreach ($activities as $category) {
                    $rate = $this->rateFor($employee, $category, $rates);
                    if (! $rate) {
                        continue;
                    }
                    foreach ($days as $day) {
                        $key = $category->id.'|'.$day->date->toDateString();
                        $count = $counts->get($key);
                        if (! $count?->usable()) {
                            $keep[$key] = true;

                            continue;
                        }
                        $away = ($category->deduct_leave && $day->kind === PlannedDay::LEAVE) || ($category->other_absence && $absent->has($employee->id.'|'.$day->date->toDateString()));
                        if ($count->count > 0 && ! $away) {
                            $due[$key] = [$category, $rate, $day, $count->count, 'Activities on the day: '.rtrim(rtrim(number_format($count->count, 2, '.', ''), '0'), '.')];
                        }
                    }
                }
            }
            DB::transaction(function () use ($employee, $from, $to, $due, $keep, &$added, &$removed) {
                $existing = DailyAllowance::where('employee_id', $employee->id)->where('source', 'auto')
                    ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)->get()
                    ->keyBy(fn ($e) => $e->allowance_category_id.'|'.$e->start_date->toDateString());
                $imported = DailyAllowance::where('employee_id', $employee->id)->where('source', 'generated')
                    ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)->get(['allowance_category_id', 'start_date'])
                    ->map(fn ($e) => $e->allowance_category_id.'|'.$e->start_date->toDateString())->flip();
                foreach ($due as $key => [$category, $rate, $day, $items, $reason]) {
                    $row = $existing->get($key);
                    if ($row?->payroll_sheet_detail_id || $imported->has($key)) {
                        continue;
                    }
                    $values = [
                        'department_allowance_id' => $rate->id, 'category' => $category->category, 'duration' => $category->duration,
                        'items' => $items, 'amount_per_item' => $rate->amount, 'amount' => round($items * $rate->amount, 2), 'end_date' => $day->date->toDateString(),
                        'status' => 'approved', 'is_active' => true, 'is_hod_approved' => true, 'is_hr_approved' => true,
                        'description' => $reason,
                    ];
                    if ($row) {
                        $row->update($values);
                    } else {
                        DailyAllowance::create($values + ['employee_id' => $employee->id, 'allowance_category_id' => $category->id, 'start_date' => $day->date->toDateString(), 'source' => 'auto']);
                        $added++;
                    }
                }
                foreach ($existing as $key => $row) {
                    if (! isset($due[$key]) && ! isset($keep[$key]) && ! $row->payroll_sheet_detail_id) {
                        $row->forceDelete();
                        $removed++;
                    }
                }
            });
        }

        return ['added' => $added, 'removed' => $removed];
    }

    private function reason(AllowanceCategory $category, PlannedDay $day): string
    {
        return match ($category->duration) {
            'night' => "Night duty {$day->code} {$day->start}–{$day->end}",
            'holiday' => "Worked on {$day->holiday}".($day->code ? " ({$day->code})" : ''),
            'month' => 'Monthly allowance',
            default => 'Working day'.($day->code ? " ({$day->code} {$day->start}–{$day->end})" : ''),
        };
    }

    /**
     * Claim an allowance with a count (e.g. 3 theatre cases) for a day; approved through the workflow.
     *
     * @param  array{allowance_category_id: int, date: string, items: numeric, description?: string|null}  $data
     */
    public function claim(Employee $employee, array $data, User $by): DailyAllowance
    {
        $category = AllowanceCategory::where('is_active', true)->findOrFail($data['allowance_category_id']);
        if ($category->auto()) {
            throw ValidationException::withMessages(['allowance_category_id' => "{$category->category} is worked out automatically from the roster and working hours."]);
        }
        if ($category->activity()) {
            throw ValidationException::withMessages(['allowance_category_id' => "{$category->category} is worked out from the daily activity count."]);
        }
        if ($category->generation === 'assigned') {
            throw ValidationException::withMessages(['allowance_category_id' => "{$category->category} is assigned to employees as a monthly amount."]);
        }
        if (! $this->eligible($employee)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name}'s contract does not give allowances."]);
        }
        $rate = $this->rateFor($employee, $category);
        if (! $rate) {
            throw ValidationException::withMessages(['allowance_category_id' => "There is no {$category->category} rate for {$employee->full_name}'s department and position."]);
        }
        $date = Carbon::parse($data['date'])->startOfDay();
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'Claim allowances for days that have passed.']);
        }
        $items = (float) $data['items'];
        $claim = DailyAllowance::create([
            'employee_id' => $employee->id, 'department_allowance_id' => $rate->id, 'allowance_category_id' => $category->id, 'category' => $category->category,
            'duration' => $category->duration, 'items' => $items, 'amount_per_item' => $rate->amount, 'amount' => round($items * $rate->amount, 2),
            'start_date' => $date, 'end_date' => $date, 'status' => 'pending', 'source' => 'claim', 'requested_by' => $by->id, 'description' => $data['description'] ?? null,
        ]);
        if (! $this->workflow->start(AllowanceClaimProcess::KEY, $claim, $by)) {
            $this->approve($claim);
        }

        return $claim->refresh();
    }

    public function approve(DailyAllowance $claim): void
    {
        $claim->update(['status' => 'approved', 'is_active' => true, 'is_hr_approved' => true, 'decided_at' => now()]);
        $claim->employee?->user?->notify(new Notice('Allowance approved', sprintf('%s for %s: %s.', $claim->category, $claim->start_date->format('d M Y'), number_format($claim->amount, 2)), '/self-service/allowances', 'cash-coin'));
    }

    public function reject(DailyAllowance $claim, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $claim->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    /** Totals per employee and category for a period (approved). @return Collection<int, array{employee_id: int, category: string, items: float, amount: float}> */
    public function totals(Carbon $from, Carbon $to, ?Collection $employeeIds = null): Collection
    {
        return DailyAllowance::where('status', 'approved')->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)
            ->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->selectRaw('employee_id, category, sum(items) as items, sum(amount) as amount')->groupBy('employee_id', 'category')->get()
            ->map(fn ($r) => ['employee_id' => (int) $r->employee_id, 'category' => $r->category, 'items' => (float) $r->items, 'amount' => (float) $r->amount]);
    }
}
