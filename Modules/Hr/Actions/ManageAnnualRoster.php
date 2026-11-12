<?php

namespace Modules\Hr\Actions;

use App\Models\AnnualLeaveRoster;
use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Support\LeaveBalances;
use Modules\Hr\Support\LeaveDays;
use Modules\Hr\Support\LeavePeriods;

/**
 * Annual leave rosters (HRMS leave rosters): a department or section plans when each employee takes annual leave in a
 * leave year. Plans are counted by the annual leave rule, cannot overlap and cannot go over the employee's balance.
 * Publishing shows the plans to employees, who request them from My leave; a plan follows its request
 * (planned → requested → approved, back to planned if not approved or cancelled). Employees are reminded 30 days
 * before a planned leave they have not requested.
 */
class ManageAnnualRoster
{
    public const TYPE = 'annual_leave';

    public const REMIND_DAYS = 30;

    public function annualType(): LeaveType
    {
        $type = LeaveType::where('kind', 'annual')->where('is_active', true)->orderBy('id')->first();
        if (! $type) {
            throw ValidationException::withMessages(['roster' => 'Set up an active annual leave type first (Leave types).']);
        }

        return $type;
    }

    public function create(int $departmentId, ?int $sectionId, ?int $year, User $by): Roster
    {
        $department = Department::findOrFail($departmentId);
        if ($department->parent_id) {
            [$section, $department] = [$department, Department::findOrFail($department->parent_id)];
        } else {
            $section = $sectionId ? Department::where('parent_id', $department->id)->findOrFail($sectionId) : null;
        }
        $periods = app(LeavePeriods::class);
        $year ??= $periods->companyYear();
        $exists = Roster::where('type', self::TYPE)->where('department_id', $department->id)->where('roster_year', (string) $year)
            ->when($section, fn ($q) => $q->where('section_id', $section->id), fn ($q) => $q->whereNull('section_id'))->first();
        if ($exists) {
            throw ValidationException::withMessages(['year' => "{$exists->title} already exists."]);
        }
        $start = Carbon::create($year, $periods->settings()->leave_year_start_month ?: 1, 1)->startOfDay();

        return Roster::create([
            'department_id' => $department->id, 'section_id' => $section?->id, 'type' => self::TYPE, 'status' => Roster::DRAFT, 'is_active' => false,
            'title' => ($section?->name ?? $department->name).' · Annual leave '.($start->month === 1 ? $year : $year.'/'.substr((string) ($year + 1), 2)),
            'from_date' => $start, 'to_date' => $start->copy()->addYear()->subDay(), 'roster_year' => (string) $year,
            'prepared_by' => $by->employee?->id, 'created_by' => $by->id,
        ]);
    }

    /** @return Collection<int, Employee> */
    public function employees(Roster $roster): Collection
    {
        return app(ManageRosters::class)->employees($roster)->merge(Employee::whereIn('id', $roster->annualLeaves()->pluck('employee_id'))->get())->unique('id')->values();
    }

    /** @param array{employee_id: int, start_date: string, days: int, description?: string|null} $data */
    public function save(Roster $roster, array $data, User $by, ?AnnualLeaveRoster $plan = null): AnnualLeaveRoster
    {
        if ($plan && $plan->status !== AnnualLeaveRoster::PLANNED) {
            throw ValidationException::withMessages(['plan' => 'This leave has been requested; change the request instead.']);
        }
        $employee = $this->employees($roster)->firstWhere('id', (int) $data['employee_id']);
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'This employee is not in this department.']);
        }
        $type = $this->annualType();
        $balances = app(LeaveBalances::class);
        if ($reason = $balances->ineligible($employee, $type)) {
            throw ValidationException::withMessages(['employee_id' => $reason]);
        }
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $days = (int) $data['days'];
        if ($start->lt($roster->from_date) || $start->gt($roster->to_date)) {
            throw ValidationException::withMessages(['start_date' => 'Plan leave that starts in '.$roster->from_date->format('d M Y').' – '.$roster->to_date->format('d M Y').'.']);
        }
        $end = app(LeaveDays::class)->endDate($employee, $type, $start, $days);

        $clash = AnnualLeaveRoster::where('employee_id', $employee->id)->whereIn('status', [AnnualLeaveRoster::PLANNED, AnnualLeaveRoster::REQUESTED, AnnualLeaveRoster::TAKEN])
            ->when($plan, fn ($q) => $q->whereKeyNot($plan->id))->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => "{$employee->full_name} already has leave planned from {$clash->start_date->format('d M Y')} to {$clash->end_date->format('d M Y')}."]);
        }
        $leave = EmpLeave::with('leaveType')->where('employee_id', $employee->id)->whereIn('status', EmpLeave::OPEN)->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
        if ($leave) {
            throw ValidationException::withMessages(['start_date' => "{$employee->full_name} is on {$leave->leaveType?->name} from {$leave->start_date->format('d M Y')} to {$leave->end_date->format('d M Y')}."]);
        }

        $year = app(LeavePeriods::class)->yearOf($employee, $type, $start);
        $balances->forget();
        $available = $balances->for($employee, $type, $year)['available'];
        $planned = (float) AnnualLeaveRoster::where('employee_id', $employee->id)->where('leave_year', (string) $year)->where('status', AnnualLeaveRoster::PLANNED)
            ->when($plan, fn ($q) => $q->whereKeyNot($plan->id))->sum('days');
        if ($days > $available - $planned) {
            throw ValidationException::withMessages(['days' => "{$employee->full_name} has ".max(0, $available - $planned).' days of annual leave left to plan.']);
        }

        $plan ??= new AnnualLeaveRoster(['roster_id' => $roster->id, 'status' => AnnualLeaveRoster::PLANNED, 'created_by' => $by->id]);
        $plan->fill([
            'employee_id' => $employee->id, 'department_id' => (string) $employee->department_id, 'start_date' => $start, 'end_date' => $end, 'days' => $days,
            'leave_year' => (string) $year, 'description' => $data['description'] ?? null, 'reminded_at' => null,
        ])->save();

        return $plan->refresh();
    }

    public function remove(AnnualLeaveRoster $plan): void
    {
        if ($plan->status !== AnnualLeaveRoster::PLANNED) {
            throw ValidationException::withMessages(['plan' => 'This leave has been requested and cannot be removed from the roster.']);
        }
        $plan->delete();
    }

    public function publish(Roster $roster): Roster
    {
        if (! $roster->annualLeaves()->exists()) {
            throw ValidationException::withMessages(['roster' => 'Plan some leave before publishing.']);
        }
        $first = $roster->status !== Roster::PUBLISHED;
        $roster->update(['status' => Roster::PUBLISHED, 'published_at' => now(), 'is_active' => true]);
        if ($first) {
            $roster->annualLeaves()->with('employee.user')->where('status', AnnualLeaveRoster::PLANNED)->get()->groupBy('employee_id')->each(function ($plans) {
                $lines = $plans->map(fn ($p) => $p->start_date->format('d M Y').' – '.$p->end_date->format('d M Y').' ('.$p->days.' days)')->implode('; ');
                $plans->first()->employee?->user?->notify(new Notice('Annual leave planned', "Your annual leave is planned for {$lines}. Request it from My leave.", '/self-service/leave', 'calendar-range'));
            });
        }

        return $roster->refresh();
    }

    /** The employee's plan that can be requested (published and still planned). */
    public function requestable(int $employeeId, int $planId): AnnualLeaveRoster
    {
        $plan = AnnualLeaveRoster::with('roster')->where('employee_id', $employeeId)->find($planId);
        if (! $plan || $plan->status !== AnnualLeaveRoster::PLANNED || $plan->roster?->status !== Roster::PUBLISHED) {
            throw ValidationException::withMessages(['annual_plan_id' => 'This planned leave cannot be requested (already requested, or not published).']);
        }

        return $plan;
    }

    /** Links a leave request to the employee's plan. */
    public function link(EmpLeave $leave, int $planId): void
    {
        $this->requestable($leave->employee_id, $planId)->update(['emp_leave_id' => $leave->id]);
        $this->sync($leave);
    }

    /** Keeps a plan in step with its request. */
    public function sync(EmpLeave $leave): void
    {
        $plan = AnnualLeaveRoster::where('emp_leave_id', $leave->id)->first();
        if (! $plan) {
            return;
        }
        match (true) {
            $leave->status === EmpLeave::PENDING => $plan->update(['status' => AnnualLeaveRoster::REQUESTED]),
            in_array($leave->status, EmpLeave::TAKEN, true) => $plan->update(['status' => AnnualLeaveRoster::TAKEN]),
            default => $plan->update(['status' => AnnualLeaveRoster::PLANNED, 'emp_leave_id' => null]),
        };
    }

    /** Reminds employees of planned leave starting within 30 days that they have not requested. */
    public function remind(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $plans = AnnualLeaveRoster::with('employee.user')->where('status', AnnualLeaveRoster::PLANNED)->whereNull('reminded_at')
            ->whereHas('roster', fn ($q) => $q->where('status', Roster::PUBLISHED))
            ->whereDate('start_date', '>=', $today)->whereDate('start_date', '<=', $today->copy()->addDays(self::REMIND_DAYS))->get();
        $plans->each(function (AnnualLeaveRoster $p) {
            $p->employee?->user?->notify(new Notice('Request your planned leave', "Your annual leave is planned from {$p->start_date->format('d M Y')} ({$p->days} days). Request it from My leave.", '/self-service/leave', 'calendar-range'));
            $p->update(['reminded_at' => now()]);
        });

        return $plans->count();
    }

    public function delete(Roster $roster): void
    {
        if ($roster->annualLeaves()->where('status', '!=', AnnualLeaveRoster::PLANNED)->exists()) {
            throw ValidationException::withMessages(['roster' => 'Some planned leave has been requested; the roster cannot be deleted.']);
        }
        DB::transaction(function () use ($roster) {
            $roster->annualLeaves()->delete();
            $roster->delete();
        });
    }
}
