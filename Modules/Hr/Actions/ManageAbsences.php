<?php

namespace Modules\Hr\Actions;

use App\Models\AbsenceRequest;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Workflow\AbsenceRequestProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Official absences: one record per employee, each through the `absence-request` workflow (approved at once when none is
 * set up). Approved days are excused in attendance, so the days are worked out again on every change.
 */
class ManageAbsences
{
    public const WITHDRAWN = 'Withdrawn by the requester.';

    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageAttendance $attendance) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array{from_date: string, to_date: string, from_time?: string|null, to_time?: string|null, address: string, description: string, has_per_diem?: bool}  $d
     * @return Collection<int, AbsenceRequest>
     */
    public function create(Collection $employees, array $d, User $by): Collection
    {
        $from = Carbon::parse($d['from_date'])->startOfDay();
        $to = Carbon::parse($d['to_date'])->startOfDay();
        $clashes = $employees->map(fn (Employee $e) => $this->clash($e, $from, $to))->filter();
        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['employee_ids' => $clashes->values()->all()]);
        }

        $rows = DB::transaction(fn () => $employees->map(fn (Employee $e) => AbsenceRequest::create([
            'employee_id' => $e->id, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'from_time' => $d['from_time'] ?? null, 'to_time' => $d['to_time'] ?? null, 'number_of_days' => (int) $from->diffInDays($to) + 1,
            'address' => $d['address'], 'description' => $d['description'], 'has_per_diem' => (bool) ($d['has_per_diem'] ?? false),
            'status' => 'pending', 'requested_by' => $by->id, 'last_updated_by' => $by->employee?->id,
        ])));

        return $rows->map(function (AbsenceRequest $row) use ($by) {
            if (! $this->workflow->start(AbsenceRequestProcess::KEY, $row, $by)) {
                $this->approve($row);
            }

            return $row->refresh();
        });
    }

    public function approve(AbsenceRequest $absence): void
    {
        $absence->update(['status' => 'approved', 'decided_at' => now()]);
        $this->recompile($absence);
        $absence->employee?->user?->notify(new Notice('Official absence approved',
            'Your official absence '.$absence->from_date->format('d M').' – '.$absence->to_date->format('d M Y').' is approved.', '/self-service/attendance', 'briefcase'));
    }

    public function reject(AbsenceRequest $absence, ?string $comment): void
    {
        $withdrawn = $comment === self::WITHDRAWN;
        $absence->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decided_at' => now(), 'decision_comment' => $withdrawn ? null : $comment]);
    }

    /** Withdraws a pending absence (the requester) or cancels an approved one that has not ended. */
    public function cancel(AbsenceRequest $absence, User $by): AbsenceRequest
    {
        if (! in_array($absence->shownStatus(), ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['absence' => 'This absence has ended or is already closed.']);
        }
        $instance = $absence->status === 'pending' ? $absence->workflow()->where('status', 'pending')->first() : null;
        if ($instance && $instance->initiated_by === $by->id) {
            $this->workflow->cancel($instance, $by);
        } elseif ($instance) {
            throw ValidationException::withMessages(['absence' => 'Only the person who recorded it can withdraw a pending absence.']);
        } else {
            $absence->update(['status' => 'cancelled', 'decided_at' => now()]);
            $this->recompile($absence);
        }

        return $absence->refresh();
    }

    /** The approved or ended absence covering a day, if any. */
    public function on(int $employeeId, Carbon|string $date): ?AbsenceRequest
    {
        return AbsenceRequest::where('employee_id', $employeeId)->whereIn('status', AbsenceRequest::EXCUSED)
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->first();
    }

    private function clash(Employee $e, Carbon $from, Carbon $to): ?string
    {
        $leave = EmpLeave::where('employee_id', $e->id)->whereIn('status', [...EmpLeave::OPEN, EmpLeave::REPORTED])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->first();
        if ($leave) {
            return "{$e->full_name} is on leave ".Carbon::parse($leave->start_date)->format('d M').' – '.Carbon::parse($leave->end_date)->format('d M Y').'.';
        }
        $other = AbsenceRequest::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->first();

        return $other ? "{$e->full_name} already has an official absence ".$other->from_date->format('d M').' – '.$other->to_date->format('d M Y').'.' : null;
    }

    private function recompile(AbsenceRequest $absence): void
    {
        $this->attendance->compile($absence->from_date, $absence->to_date, collect([$absence->employee_id]));
    }
}
