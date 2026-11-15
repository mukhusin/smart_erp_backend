<?php

namespace Modules\Hr\Workflow;

use App\Models\Employee;
use App\Models\RosterChangeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Hr\Actions\ManageRosters;
use Modules\Workflow\Contracts\WorkflowProcess;

class DutyRosterChangeProcess implements WorkflowProcess
{
    public const KEY = 'duty-roster-change';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'roster';
    }

    public function name(): string
    {
        return 'Duty roster change';
    }

    public function description(): string
    {
        return 'Changes to a published duty roster made by someone who does not head the department (e.g. a head of section). Applied when approved.';
    }

    /** @param RosterChangeRequest $subject */
    public function summary(Model $subject): array
    {
        $items = collect($subject->items ?? []);
        $names = Employee::withoutGlobalScopes()->whereIn('id', $items->pluck('employee_id'))->get()->keyBy('id');
        $lines = $items->take(12)->map(fn ($i) => ($names[$i['employee_id']]?->full_name ?? 'Employee').', '.Carbon::parse($i['date'])->format('D d M').': '
            .($i['from'] ?? 'no duty').' → '.($i['to'] ?? 'no duty'))->all();

        return [
            'title' => 'Roster change: '.$subject->roster?->title,
            'lines' => array_values(array_filter([
                $items->count().' day'.($items->count() === 1 ? '' : 's').' changed'.($subject->requester ? ' by '.$subject->requester->display_name : ''),
                $subject->reason ? 'Reason: '.$subject->reason : null,
                ...$lines,
                $items->count() > 12 ? '… and '.($items->count() - 12).' more' : null,
            ])),
            'link' => "/roster/rosters/{$subject->roster_id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->roster?->department?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->roster?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageRosters::class)->applyChange($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        $subject->update(['status' => $comment === 'Withdrawn by the requester.' ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }
}
