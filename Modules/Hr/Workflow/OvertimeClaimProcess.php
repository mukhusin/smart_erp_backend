<?php

namespace Modules\Hr\Workflow;

use App\Models\Overtime;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageOvertime;
use Modules\Workflow\Contracts\WorkflowProcess;

class OvertimeClaimProcess implements WorkflowProcess
{
    public const KEY = 'overtime-claim';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'overtime';
    }

    public function name(): string
    {
        return 'Overtime claim';
    }

    public function description(): string
    {
        return 'Overtime worked outside the planned shift. HRMS: head of department, then HR.';
    }

    /** @param Overtime $subject */
    public function summary(Model $subject): array
    {
        $p = $subject->planned ?? [];
        $shifts = collect($p['shifts'] ?? [])->map(fn ($s) => $s['label'].' '.substr($s['start'], 11).'–'.substr($s['end'], 11))->implode(', ');

        return [
            'title' => 'Overtime for '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                $subject->date_of_task_done->format('D d M Y').': '.$subject->from_datetime->format('H:i').' – '.$subject->to_datetime->format('H:i')
                    .($subject->to_datetime->isSameDay($subject->from_datetime) ? '' : ' (next day)'),
                'Overtime: '.ManageOvertime::duration($subject->overtime_minutes).' = '.number_format($subject->amount, 2)
                    .' ('.($subject->rate_unit === 'shift' ? 'one shift at ' : 'at ').number_format((float) $subject->rate_amount, 2).($subject->rate_unit === 'shift' ? '' : ' an hour').')',
                $shifts ? 'Planned shift: '.$shifts.' (not counted)' : 'Planned: '.($p['day_label'] ?? 'day off').' (all counted)',
                'Task: '.$subject->task_title,
                $subject->description ? 'Details: '.$subject->description : null,
                $subject->source !== 'employee' && $subject->requester ? 'Entered by '.$subject->requester->display_name : null,
                ...collect($p['changes'] ?? [])->map(fn ($c) => 'Times changed'.($c['step'] ? " at {$c['step']}" : '')." by {$c['by']}: {$c['before']} → {$c['after']}".($c['comment'] ? " ({$c['comment']})" : ''))->all(),
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
        app(ManageOvertime::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageOvertime::class)->reject($subject, $comment);
    }
}
