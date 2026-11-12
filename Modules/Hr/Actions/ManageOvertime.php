<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\Overtime;
use App\Models\OvertimeRate;
use App\Models\User;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Services\AccessService;
use Modules\Hr\Workflow\OvertimeClaimProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Overtime claims (HRMS overtime), without needing attendance.
 *
 * The claim gives a day, a start and an end time (an end before the start runs past midnight). Only the time outside
 * the employee's planned shift counts: the roster duty or working hours of that day, and a night shift of the day
 * before that ends on it. Days off and public holidays count in full. Paid by the most specific overtime rate of the
 * employee's department or section and position: per hour (by the minutes), or per shift (one shift per claim), and only
 * for contract types that give overtime. The "Overtime claim" approval workflow decides; the current approver or HR
 * can change the times.
 */
class ManageOvertime
{
    public const MIN_MINUTES = 15;

    public const MAX_HOURS = 16;

    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function eligible(Employee $employee): bool
    {
        return $employee->employment_status !== 'terminated'
            && (bool) $employee->activeContract()->with('natureOfContract')->first()?->natureOfContract?->has_overtime;
    }

    public function rateFor(Employee $employee): ?OvertimeRate
    {
        return OvertimeRate::where('is_active', true)->where('department_id', $employee->department_id)->get()
            ->filter(fn ($r) => (! $r->section_id || (int) $r->section_id === (int) $employee->section_id) && (! $r->job_position_id || (int) $r->job_position_id === (int) $employee->job_position_id))
            ->sortByDesc(fn ($r) => ($r->section_id ? 2 : 0) + ($r->job_position_id ? 1 : 0))->first();
    }

    /**
     * Works out the overtime of a period: total minutes, minutes inside the planned shift, and overtime minutes.
     *
     * @return array{from: Carbon, to: Carbon, total_minutes: int, planned_minutes: int, overtime_minutes: int, day: string, day_label: string, shifts: list<array{label: string, start: string, end: string}>}
     */
    public function compute(Employee $employee, string $date, string $start, string $end): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $from = $day->copy()->setTimeFromTimeString($start);
        $to = $day->copy()->setTimeFromTimeString($end);
        if ($to->lte($from)) {
            $to->addDay();
        }
        if ($from->diffInMinutes($to) > self::MAX_HOURS * 60) {
            throw ValidationException::withMessages(['end_time' => 'An overtime claim is at most '.self::MAX_HOURS.' hours.']);
        }
        $calendar = app(WorkCalendar::class);
        $days = $calendar->days($employee, $day->copy()->subDay(), $day->copy()->addDay())->values();
        $today = $days[1];
        if ($today->kind === PlannedDay::LEAVE) {
            throw ValidationException::withMessages(['date' => "{$employee->full_name} is on {$today->label} that day."]);
        }
        $shifts = collect($days)->filter->isWorking()->map(fn (PlannedDay $d) => [$d, $d->startsAt(), $d->endsAt()])
            ->filter(fn ($s) => $s[1]->lt($to) && $s[2]->gt($from))->values();
        $planned = (int) $shifts->sum(fn ($s) => max(0, $s[1]->max($from)->diffInMinutes($s[2]->min($to), false)));
        $total = (int) $from->diffInMinutes($to);

        return [
            'from' => $from, 'to' => $to, 'total_minutes' => $total, 'planned_minutes' => min($planned, $total), 'overtime_minutes' => max(0, $total - $planned),
            'day' => $today->kind, 'day_label' => $today->holiday ?? $today->label,
            'shifts' => $shifts->map(fn ($s) => ['label' => trim(($s[0]->code ? $s[0]->code.' ' : '').$s[0]->label), 'start' => $s[1]->format('Y-m-d H:i'), 'end' => $s[2]->format('Y-m-d H:i')])->all(),
        ];
    }

    /**
     * @param  array{date: string, start_time: string, end_time: string, task_title: string, description?: string|null}  $data
     */
    public function claim(Employee $employee, array $data, User $by, string $source = 'employee'): Overtime
    {
        if (! $this->eligible($employee)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name}'s contract does not give overtime."]);
        }
        $rate = $this->rateFor($employee);
        if (! $rate) {
            throw ValidationException::withMessages(['employee_id' => "There is no overtime rate for {$employee->full_name}'s department and position."]);
        }
        $c = $this->check($employee, $data['date'], $data['start_time'], $data['end_time']);

        $claim = Overtime::create([
            'employee_id' => $employee->id, 'department_id' => $employee->department_id, 'section_id' => $employee->section_id, 'overtime_rate_id' => $rate->id,
            'task_title' => $data['task_title'], 'description' => $data['description'] ?? null, 'date_of_task_done' => Carbon::parse($data['date'])->toDateString(),
            'status' => 'pending', 'source' => $source, 'requested_by' => $by->id, 'type' => 'claim',
        ] + $this->money($c, $rate));
        if (! $this->workflow->start(OvertimeClaimProcess::KEY, $claim, $by)) {
            $this->approve($claim);
        }

        return $claim->refresh();
    }

    /** @return array<string, mixed> */
    private function check(Employee $employee, string $date, string $start, string $end, ?Overtime $except = null): array
    {
        $c = $this->compute($employee, $date, $start, $end);
        if ($c['to']->isFuture()) {
            throw ValidationException::withMessages(['end_time' => 'Claim overtime after the work is done.']);
        }
        if ($c['overtime_minutes'] < self::MIN_MINUTES) {
            throw ValidationException::withMessages(['start_time' => 'This time is inside the planned shift ('.collect($c['shifts'])->map(fn ($s) => substr($s['start'], 11).'–'.substr($s['end'], 11))->implode(', ').'); only time outside it is overtime.']);
        }
        $overlap = Overtime::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->where('from_datetime', '<', $c['to'])->where('to_datetime', '>', $c['from'])->first();
        if ($overlap) {
            throw ValidationException::withMessages(['start_time' => 'Overlaps another overtime claim ('.$overlap->from_datetime->format('d M H:i').' – '.$overlap->to_datetime->format('H:i').').']);
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private function money(array $c, OvertimeRate $rate): array
    {
        $minutes = $c['overtime_minutes'];
        $amount = $rate->unit === 'shift' ? $rate->amount : round($minutes / 60 * $rate->amount, 2);

        return [
            'from_datetime' => $c['from'], 'to_datetime' => $c['to'], 'hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60, 'overtime_minutes' => $minutes,
            'rate_unit' => $rate->unit, 'rate_amount' => $rate->amount, 'amount_per_minute' => $rate->unit === 'hour' ? round($rate->amount / 60, 4) : 0, 'amount' => $amount,
            'planned' => ['day' => $c['day'], 'day_label' => $c['day_label'], 'total_minutes' => $c['total_minutes'], 'planned_minutes' => $c['planned_minutes'], 'shifts' => $c['shifts']],
        ];
    }

    public function canChange(Overtime $claim, User $user): bool
    {
        if ($claim->status !== 'pending') {
            return false;
        }
        if (app(AccessService::class)->can($user, 'overtime.manage')) {
            return true;
        }

        return $claim->workflow && $claim->workflow->status === 'pending' && $this->workflow->canAct($user, $claim->workflow);
    }

    /** Change the times while it waits for approval (HR or the current approver); kept in the claim's history. */
    public function changeTimes(Overtime $claim, string $start, string $end, User $by, ?string $comment): Overtime
    {
        abort_unless($this->canChange($claim, $by), 403, 'Only HR or the current approver can change the times.');
        $c = $this->check($claim->employee, $claim->date_of_task_done->toDateString(), $start, $end, $claim);
        $history = $claim->planned['changes'] ?? [];
        $history[] = [
            'by' => $by->display_name, 'step' => $claim->workflow?->currentStep()?->name, 'at' => now()->toIso8601String(), 'comment' => $comment,
            'before' => $claim->from_datetime->format('H:i').'–'.$claim->to_datetime->format('H:i'), 'after' => $c['from']->format('H:i').'–'.$c['to']->format('H:i'),
        ];
        $values = $this->money($c, $claim->rate ?? $this->rateFor($claim->employee));
        $values['planned']['changes'] = $history;
        $claim->update($values);

        return $claim->refresh();
    }

    public function approve(Overtime $claim): void
    {
        $claim->update(['status' => 'approved', 'is_active' => true, 'is_hr_approved' => true, 'decided_at' => now()]);
        $claim->employee?->user?->notify(new Notice('Overtime approved', sprintf('Overtime on %s (%s) is approved: %s.', $claim->date_of_task_done->format('d M Y'),
            self::duration($claim->overtime_minutes), number_format($claim->amount, 2)), '/self-service/overtime', 'hourglass-split'));
    }

    public function reject(Overtime $claim, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $claim->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $claim->employee?->user?->notify(new Notice('Overtime not approved', "Overtime on {$claim->date_of_task_done->format('d M Y')} was not approved.".($comment ? " Reason: {$comment}" : ''), '/self-service/overtime', 'hourglass-split'));
        }
    }

    public function withdraw(Overtime $claim, User $by): Overtime
    {
        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages(['overtime' => 'Only a claim waiting for approval can be withdrawn.']);
        }
        if ($instance = $claim->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, $by);
        } else {
            $this->reject($claim, 'Withdrawn by the requester.');
        }

        return $claim->refresh();
    }

    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h} h " : '').($m ? "{$m} min" : ''));
    }
}
