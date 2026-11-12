<?php

namespace Modules\Hr\Actions;

use App\Models\EmpLeave;
use App\Models\EmpLeaveChange;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\WorkCalendar;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\LeaveBalances;
use Modules\Hr\Support\LeaveDays;
use Modules\Hr\Support\LeavePeriods;
use Modules\Hr\Workflow\LeaveRequestProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Leave requests (HRMS leave and annual leave requests in one flow).
 *
 * The employee (or HR on their behalf) asks for a leave type, a first day and a number of days; the last day follows
 * the type's counting rule and the employee's calendar. Checks: eligibility, notice, balance (yearly types) or the
 * days allowed each time, no other leave on those days, a document when the type needs one, and the deceased's
 * details for compassionate leave (once per relationship, except children). The "Leave request" approval workflow
 * decides (HRMS: head of department, then HR); the current approver or HR can change the dates. Approved days go on
 * the working calendar; a daily job marks leave started and ended; HR can record an early return.
 */
class ManageLeave
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    private function days(): LeaveDays
    {
        return app(LeaveDays::class);
    }

    private function balances(): LeaveBalances
    {
        return app(LeaveBalances::class);
    }

    private function periods(): LeavePeriods
    {
        return app(LeavePeriods::class);
    }

    /**
     * @param  array{leave_type_id: int, start_date: string, days: int, description?: string|null, leave_address?: string|null, leave_phone?: string|null, agreed?: bool, details?: array|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function request(Employee $employee, array $data, User $by, string $source = 'employee', array $files = []): EmpLeave
    {
        $type = LeaveType::findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $days = (int) $data['days'];
        $end = $this->days()->endDate($employee, $type, $start, $days);
        if (! empty($data['annual_plan_id'])) {
            if (! $type->isAnnual()) {
                throw ValidationException::withMessages(['annual_plan_id' => 'Planned leave is requested as annual leave.']);
            }
            app(ManageAnnualRoster::class)->requestable($employee->id, (int) $data['annual_plan_id']);
        }
        $this->check($employee, $type, $start, $end, $days, null, $source === 'employee');
        if ($source === 'employee' && $type->requires_document && ! $files) {
            throw ValidationException::withMessages(['files' => "Attach a supporting document for {$type->name} (e.g. a medical certificate)."]);
        }
        $details = $this->details($employee, $type, $data['details'] ?? null);

        $leave = DB::transaction(function () use ($employee, $type, $start, $end, $days, $data, $by, $source, $files, $details) {
            $leave = EmpLeave::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'department_id' => (string) $employee->department_id,
                'start_date' => $start, 'end_date' => $end, 'requested_start_date' => $start, 'requested_end_date' => $end,
                'days' => $days, 'days_requested' => $days, 'leave_year' => (string) $this->periods()->yearOf($employee, $type, $start),
                'description' => $data['description'] ?? null, 'details' => $details, 'leave_address' => $data['leave_address'] ?? null,
                'leave_phone' => $data['leave_phone'] ?? null, 'status' => EmpLeave::PENDING, 'source' => $source, 'requested_by' => $by->id,
                'agreed_at' => ! empty($data['agreed']) ? now() : null,
            ]);
            foreach ($files as $file) {
                $leave->files()->create(['file_path' => EmployeeFiles::store($file, $employee, 'leave'), 'file_name' => $file->getClientOriginalName(), 'size' => $file->getSize()]);
            }
            if (! empty($data['annual_plan_id'])) {
                app(ManageAnnualRoster::class)->link($leave, (int) $data['annual_plan_id']);
            }

            return $leave;
        });

        if (! $this->workflow->start(LeaveRequestProcess::KEY, $leave, $by)) {
            $this->approve($leave->refresh());
        }

        return $leave->refresh();
    }

    /** Validates a leave period. `$except` is the leave being changed. */
    public function check(Employee $employee, LeaveType $type, Carbon $start, Carbon $end, int $days, ?EmpLeave $except, bool $byEmployee): void
    {
        if ($reason = $this->balances()->ineligible($employee, $type)) {
            throw ValidationException::withMessages(['leave_type_id' => $reason]);
        }
        $today = now()->startOfDay();
        if ($byEmployee && $start->lt($today)) {
            throw ValidationException::withMessages(['start_date' => 'The first day cannot be in the past.']);
        }
        if ($byEmployee && $type->notice_days && $start->lt($today->copy()->addDays($type->notice_days))) {
            throw ValidationException::withMessages(['start_date' => "{$type->name} must be requested at least {$type->notice_days} days ahead: start on or after ".$today->copy()->addDays($type->notice_days)->format('d M Y').'.']);
        }
        if ($days < 1) {
            throw ValidationException::withMessages(['days' => 'Ask for at least one day.']);
        }
        if (! $type->yearly() && $days > $type->max_days) {
            throw ValidationException::withMessages(['days' => "{$type->name} is up to {$type->max_days} days each time."]);
        }
        if ($type->yearly()) {
            $year = $this->periods()->yearOf($employee, $type, $start);
            $this->balances()->forget();
            $available = $this->balances()->for($employee, $type, $year)['available'] + ($except && $except->leave_type_id === $type->id && (int) $except->leave_year === $year ? (float) $except->days : 0);
            if ($days > $available) {
                throw ValidationException::withMessages(['days' => "Only {$this->num($available)} days of {$type->name} are left for ".$this->periods()->label($employee, $type, $year).'.']);
            }
        }
        $overlap = EmpLeave::with('leaveType')->where('employee_id', $employee->id)->whereIn('status', EmpLeave::OPEN)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => "These days overlap {$overlap->leaveType?->name} from {$overlap->start_date->format('d M Y')} to {$overlap->end_date->format('d M Y')}."]);
        }
    }

    /** Compassionate leave: who died, relationship and date; once per relationship (children: once per child). */
    private function details(Employee $employee, LeaveType $type, ?array $details): ?array
    {
        if ($type->kind !== 'compassionate') {
            return null;
        }
        $name = trim((string) ($details['deceased_name'] ?? ''));
        $relationship = trim((string) ($details['relationship'] ?? ''));
        $date = $details['death_date'] ?? null;
        $errors = array_filter([
            'details.deceased_name' => $name === '' ? 'Give the name of the deceased.' : null,
            'details.relationship' => $relationship === '' ? 'Choose your relationship to the deceased.' : null,
            'details.death_date' => ! $date || Carbon::parse($date)->isFuture() ? 'Give the date of death.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $taken = EmpLeave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereNotIn('status', [EmpLeave::REJECTED, EmpLeave::CANCELLED])
            ->get()->first(fn ($l) => strcasecmp($l->details['relationship'] ?? '', $relationship) === 0
                && (strtolower($relationship) !== 'child' || strcasecmp($l->details['deceased_name'] ?? '', $name) === 0));
        if ($taken) {
            throw ValidationException::withMessages(['details.relationship' => "You already took {$type->name} for your ".strtolower($relationship).'.']);
        }

        return ['deceased_name' => $name, 'relationship' => $relationship, 'death_date' => Carbon::parse($date)->toDateString()];
    }

    public function approve(EmpLeave $leave): void
    {
        DB::transaction(function () use ($leave) {
            $leave->update(['status' => EmpLeave::APPROVED, 'decided_at' => now(), 'hr_office_is_approved' => true]);
            $this->writeCalendar($leave);
            if ($leave->start_date->lte(now()->startOfDay())) {
                $leave->update(['status' => EmpLeave::ON_LEAVE, 'is_active' => true]);
            }
            app(ManageAnnualRoster::class)->sync($leave);
        });
        $this->notify($leave, 'Leave approved', sprintf('Your %s from %s to %s (%s days) is approved.',
            $leave->leaveType?->name ?? 'leave', $leave->start_date->format('d M Y'), $leave->end_date->format('d M Y'), $this->num($leave->days)));
    }

    public function reject(EmpLeave $leave, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $leave->update(['status' => $withdrawn ? EmpLeave::CANCELLED : EmpLeave::REJECTED, 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        $this->clearCalendar($leave);
        app(ManageAnnualRoster::class)->sync($leave);
        if (! $withdrawn) {
            $this->notify($leave, 'Leave not approved', "Your {$leave->leaveType?->name} request from {$leave->start_date->format('d M Y')} was not approved.".($comment ? " Reason: {$comment}" : ''));
        }
    }

    /** Withdraw a request waiting for approval, or cancel approved leave that has not started. */
    public function withdraw(EmpLeave $leave, User $by): EmpLeave
    {
        if ($leave->status === EmpLeave::PENDING) {
            if ($instance = $leave->workflow()->where('status', 'pending')->first()) {
                $this->workflow->cancel($instance, $by);
            } else {
                $this->reject($leave, 'Withdrawn by the requester.');
            }

            return $leave->refresh();
        }
        if ($leave->status === EmpLeave::APPROVED && $leave->start_date->gt(now()->startOfDay())) {
            $leave->update(['status' => EmpLeave::CANCELLED, 'decided_at' => now()]);
            $this->clearCalendar($leave);
            app(ManageAnnualRoster::class)->sync($leave);

            return $leave->refresh();
        }
        throw ValidationException::withMessages(['leave' => 'Only leave waiting for approval, or approved leave that has not started, can be cancelled.']);
    }

    /** HR, or the approver of the current step while it waits for approval. */
    public function canChange(EmpLeave $leave, User $user): bool
    {
        if (! in_array($leave->status, [EmpLeave::PENDING, EmpLeave::APPROVED], true)) {
            return false;
        }
        if (app(AccessService::class)->can($user, 'leave.approve-hr')) {
            return true;
        }
        $instance = $leave->status === EmpLeave::PENDING ? $leave->workflow : null;

        return $instance && $instance->status === 'pending' && $this->workflow->canAct($user, $instance);
    }

    /** Change the first day and number of days (kept with who changed it and why). */
    public function changeDates(EmpLeave $leave, string $startDate, int $days, User $by, ?string $comment): EmpLeave
    {
        abort_unless($this->canChange($leave, $by), 403, 'Only HR or the current approver can change the dates.');
        $employee = $leave->employee;
        $type = $leave->leaveType;
        $start = Carbon::parse($startDate)->startOfDay();
        $end = $this->days()->endDate($employee, $type, $start, $days);
        if ($start->equalTo($leave->start_date) && $days === (int) $leave->days) {
            return $leave;
        }
        $this->check($employee, $type, $start, $end, $days, $leave, false);
        $before = ['start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'days' => (float) $leave->days];

        return DB::transaction(function () use ($leave, $employee, $type, $start, $end, $days, $by, $comment, $before) {
            $leave->update(['start_date' => $start, 'end_date' => $end, 'days' => $days, 'leave_year' => (string) $this->periods()->yearOf($employee, $type, $start)]);
            EmpLeaveChange::create([
                'emp_leave_id' => $leave->id, 'user_id' => $by->id, 'step_name' => $leave->status === EmpLeave::PENDING ? $leave->workflow?->currentStep()?->name : 'HR',
                'kind' => 'dates', 'before' => $before, 'after' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => (float) $days], 'comment' => $comment,
            ]);
            if ($leave->status === EmpLeave::APPROVED) {
                $this->writeCalendar($leave);
            }

            return $leave->refresh();
        });
    }

    /** HR records the day the employee came back; the leave ends the day before and its days are counted again. */
    public function recordReturn(EmpLeave $leave, string $reportDate, User $by, ?string $comment): EmpLeave
    {
        if (! in_array($leave->status, [EmpLeave::ON_LEAVE, EmpLeave::REPORTED, EmpLeave::APPROVED], true) || $leave->start_date->isFuture()) {
            throw ValidationException::withMessages(['report_date' => 'The leave has not started.']);
        }
        $report = Carbon::parse($reportDate)->startOfDay();
        if ($report->lte($leave->start_date) || $report->gt($leave->end_date->copy()->addDay())) {
            throw ValidationException::withMessages(['report_date' => 'The return must be after the first day and no later than the day after the last day ('.$leave->end_date->copy()->addDay()->format('d M Y').').']);
        }
        $end = $report->copy()->subDay();
        $days = $this->days()->count($leave->employee, $leave->leaveType, $leave->start_date, $end);
        $before = ['start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'days' => (float) $leave->days];

        return DB::transaction(function () use ($leave, $report, $end, $days, $by, $comment, $before) {
            $leave->update(['end_date' => $end, 'days' => $days, 'report_date' => $report, 'is_reported' => true, 'is_active' => false,
                'status' => EmpLeave::REPORTED, 'days_passed' => $days, 'hr_id' => $by->employee?->id]);
            EmpLeaveChange::create([
                'emp_leave_id' => $leave->id, 'user_id' => $by->id, 'step_name' => 'HR', 'kind' => 'return', 'before' => $before,
                'after' => ['start_date' => $leave->start_date->toDateString(), 'end_date' => $end->toDateString(), 'days' => (float) $days, 'report_date' => $report->toDateString()], 'comment' => $comment,
            ]);
            $this->writeCalendar($leave);

            return $leave->refresh();
        });
    }

    /** Daily: approved leave that started is "on leave"; leave that ended is "reported back" (the day after the last day). */
    public function updateStatuses(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $started = EmpLeave::where('status', EmpLeave::APPROVED)->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->update(['status' => EmpLeave::ON_LEAVE, 'is_active' => true]);
        $ended = 0;
        EmpLeave::whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])->whereDate('end_date', '<', $today)->each(function (EmpLeave $leave) use (&$ended) {
            $leave->update(['status' => EmpLeave::REPORTED, 'is_active' => false, 'is_reported' => true, 'report_date' => $leave->end_date->copy()->addDay(), 'days_passed' => $leave->days]);
            $ended++;
        });

        return ['started' => $started, 'ended' => $ended];
    }

    private function writeCalendar(EmpLeave $leave): void
    {
        $type = $leave->leaveType;
        EmployeeTimetable::where('source', EmployeeTimetable::LEAVE)->where('source_id', $leave->id)->delete();
        foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $day) {
            EmployeeTimetable::create([
                'employee_id' => $leave->employee_id, 'day_date' => $day->toDateString(), 'source' => EmployeeTimetable::LEAVE, 'source_id' => $leave->id,
                'code' => mb_substr($type?->code ?? 'LV', 0, 10), 'duty' => $type?->name ?? 'Leave', 'can_punch' => false, 'color' => $type?->color,
            ]);
        }
        app(WorkCalendar::class)->forget();
    }

    private function clearCalendar(EmpLeave $leave): void
    {
        EmployeeTimetable::where('source', EmployeeTimetable::LEAVE)->where('source_id', $leave->id)->delete();
        app(WorkCalendar::class)->forget();
    }

    private function notify(EmpLeave $leave, string $title, string $message): void
    {
        $leave->employee?->user?->notify(new Notice($title, $message, '/self-service/leave', 'calendar-check'));
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
