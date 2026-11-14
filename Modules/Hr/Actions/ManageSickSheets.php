<?php

namespace Modules\Hr\Actions;

use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\ExcuseOfDuty;
use App\Models\User;
use App\Services\WorkCalendar;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Workflow\ExcuseOfDutyProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Sick sheets (excuse of duty): sent by the employee or by HR on their behalf, through the `excuse-of-duty` workflow
 * (approved at once when none is set up). Approved days are written to the employee's calendar (duty roster, attendance).
 */
class ManageSickSheets
{
    public const WITHDRAWN = 'Withdrawn by the requester.';

    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageAttendance $attendance) {}

    /** @param array{start_date: string, end_date: string, hospital_name?: string|null, doctor_name?: string|null, reason?: string|null} $d */
    public function submit(Employee $employee, array $d, ?UploadedFile $file, User $by, string $source): ExcuseOfDuty
    {
        $from = Carbon::parse($d['start_date'])->startOfDay();
        $to = Carbon::parse($d['end_date'])->startOfDay();
        $leave = EmpLeave::where('employee_id', $employee->id)->whereIn('status', [...EmpLeave::OPEN, EmpLeave::REPORTED])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->first();
        if ($leave) {
            throw ValidationException::withMessages(['start_date' => "{$employee->full_name} is on leave ".Carbon::parse($leave->start_date)->format('d M').' – '.Carbon::parse($leave->end_date)->format('d M Y').'.']);
        }
        $other = ExcuseOfDuty::where('employee_id', $employee->id)->whereIn('status', ['pending', ...ExcuseOfDuty::EXCUSED])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->first();
        if ($other) {
            throw ValidationException::withMessages(['start_date' => 'There is already a sick sheet for '.$other->start_date->format('d M').' – '.$other->end_date->format('d M Y').'.']);
        }

        $sheet = ExcuseOfDuty::create([
            'employee_id' => $employee->id, 'start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'number_of_days' => (int) $from->diffInDays($to) + 1,
            'hospital_name' => $d['hospital_name'] ?? null, 'doctor_name' => $d['doctor_name'] ?? null, 'reason' => $d['reason'] ?? null,
            'status' => 'pending', 'source' => $source, 'requested_by' => $by->id, 'created_by' => $by->id,
            'file_path' => $file ? EmployeeFiles::store($file, $employee, 'sick-sheets') : null, 'file_name' => $file?->getClientOriginalName(),
        ]);
        if (! $this->workflow->start(ExcuseOfDutyProcess::KEY, $sheet, $by)) {
            $this->approve($sheet);
        }

        return $sheet->refresh();
    }

    public function approve(ExcuseOfDuty $sheet): void
    {
        $sheet->update(['status' => 'active', 'decided_at' => now()]);
        $this->writeCalendar($sheet);
        $sheet->employee?->user?->notify(new Notice('Sick sheet approved',
            'Your excuse of duty '.$sheet->start_date->format('d M').' – '.$sheet->end_date->format('d M Y').' is approved. Get well soon.', '/self-service/sick-sheets', 'bandaid'));
    }

    public function reject(ExcuseOfDuty $sheet, ?string $comment): void
    {
        $withdrawn = $comment === self::WITHDRAWN;
        $sheet->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decided_at' => now(), 'decision_comment' => $withdrawn ? null : $comment]);
        $this->clearCalendar($sheet);
    }

    /** Withdraw a pending sheet (the person who sent it) or cancel an approved one that has not ended. */
    public function cancel(ExcuseOfDuty $sheet, User $by): ExcuseOfDuty
    {
        if (! in_array($sheet->shownStatus(), ['pending', 'active'], true)) {
            throw ValidationException::withMessages(['sheet' => 'This sick sheet has ended or is already closed.']);
        }
        $instance = $sheet->status === 'pending' ? $sheet->workflow()->where('status', 'pending')->first() : null;
        if ($instance) {
            if ($instance->initiated_by !== $by->id) {
                throw ValidationException::withMessages(['sheet' => 'Only the person who sent it can withdraw a pending sick sheet.']);
            }
            $this->workflow->cancel($instance, $by);
        } else {
            $sheet->update(['status' => 'cancelled', 'decided_at' => now()]);
            $this->clearCalendar($sheet);
        }

        return $sheet->refresh();
    }

    private function writeCalendar(ExcuseOfDuty $sheet): void
    {
        EmployeeTimetable::where('source', EmployeeTimetable::EXCUSE)->where('source_id', $sheet->id)->delete();
        foreach (CarbonPeriod::create($sheet->start_date, $sheet->end_date) as $day) {
            EmployeeTimetable::create([
                'employee_id' => $sheet->employee_id, 'day_date' => $day->toDateString(), 'source' => EmployeeTimetable::EXCUSE, 'source_id' => $sheet->id,
                'code' => 'ED', 'duty' => 'Excuse of duty', 'can_punch' => false, 'color' => '#d55181',
            ]);
        }
        app(WorkCalendar::class)->forget();
        $this->attendance->compile($sheet->start_date, $sheet->end_date, collect([$sheet->employee_id]));
    }

    private function clearCalendar(ExcuseOfDuty $sheet): void
    {
        EmployeeTimetable::where('source', EmployeeTimetable::EXCUSE)->where('source_id', $sheet->id)->delete();
        app(WorkCalendar::class)->forget();
        $this->attendance->compile($sheet->start_date, $sheet->end_date, collect([$sheet->employee_id]));
    }
}
