<?php

namespace Modules\Hr\Workflow;

use App\Models\AttendanceClaim;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageAttendanceClaims;
use Modules\Workflow\Contracts\WorkflowProcess;

class AttendanceClaimProcess implements WorkflowProcess
{
    public const KEY = 'attendance-claim';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function name(): string
    {
        return 'Attendance claim';
    }

    public function description(): string
    {
        return 'An employee explains lost time on a day (forgot to punch, official duty…); approving forgives it. HRMS: head of department.';
    }

    /** @param AttendanceClaim $subject */
    public function summary(Model $subject): array
    {
        $t = $subject->transaction;

        return [
            'title' => 'Attendance claim for '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                $subject->claim_date->format('D d M Y').': '.($t?->status ?? '').($t?->punch_in_time ? ' · in '.substr($t->punch_in_time, 11, 5) : ' · no check-in').($t?->punch_out_time ? ', out '.substr($t->punch_out_time, 11, 5) : ''),
                $t ? 'Planned: '.$t->timetable_detail.($t->attendance_in_time ? ' '.substr($t->attendance_in_time, 11, 5).'–'.substr($t->attendance_out_time, 11, 5) : '') : null,
                'Lost: '.$subject->lost_hour.' h ('.number_format($subject->lost_amount, 2).') · claimed '.$subject->hr_accepted.' h',
                $subject->reason ? 'Reason: '.$subject->reason->reason : null,
                'Explanation: '.$subject->description,
            ])),
            'link' => null,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->employee?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageAttendanceClaims::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageAttendanceClaims::class)->reject($subject, $comment);
    }
}
