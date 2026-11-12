<?php

namespace Modules\Hr\Workflow;

use App\Models\EmpLeave;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageLeave;
use Modules\Hr\Support\LeaveBalances;
use Modules\Workflow\Contracts\WorkflowProcess;

class LeaveRequestProcess implements WorkflowProcess
{
    public const KEY = 'leave-request';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'leave';
    }

    public function name(): string
    {
        return 'Leave request';
    }

    public function description(): string
    {
        return 'Leave requested by an employee or by HR for them (all leave types). HRMS: head of department, then HR.';
    }

    /** @param EmpLeave $subject */
    public function summary(Model $subject): array
    {
        $type = $subject->leaveType;
        $employee = $subject->employee;
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
        $balance = $type && $employee && $type->yearly() ? app(LeaveBalances::class)->for($employee, $type, (int) $subject->leave_year) : null;
        $d = $subject->details ?? [];

        return [
            'title' => ($type?->name ?? 'Leave').' for '.($employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                $subject->start_date->format('d M Y').' to '.$subject->end_date->format('d M Y').' · '.$num($subject->days).' days'.($type && ! $type->with_pay ? ' · without pay' : ''),
                $balance ? 'Balance: '.$num($balance['available'] + ($subject->status === EmpLeave::PENDING ? $subject->days : 0)).' days before this request' : null,
                $subject->source === 'hr' ? 'Entered by HR for the employee' : null,
                $subject->description ? 'Reason: '.$subject->description : null,
                $d ? 'Deceased: '.($d['deceased_name'] ?? '').' ('.($d['relationship'] ?? '').'), died '.($d['death_date'] ?? '') : null,
                $subject->leave_address ? 'Address while on leave: '.$subject->leave_address : null,
                $subject->leave_phone ? 'Phone: '.$subject->leave_phone : null,
                $subject->files()->count() ? 'Attachments: '.$subject->files()->count() : null,
                ...$subject->changes()->with('user.employee')->get()->reverse()->map(fn ($c) => 'Dates changed'.($c->step_name ? " at {$c->step_name}" : '').' by '.($c->user?->display_name ?? 'someone')
                    .': '.$c->after['start_date'].' to '.$c->after['end_date'].', '.$num($c->after['days']).' days'.($c->comment ? " ({$c->comment})" : ''))->all(),
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
        app(ManageLeave::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageLeave::class)->reject($subject, $comment);
    }
}
