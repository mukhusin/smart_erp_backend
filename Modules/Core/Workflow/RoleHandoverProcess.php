<?php

namespace Modules\Core\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Services\Handovers;
use Modules\Workflow\Contracts\WorkflowProcess;

class RoleHandoverProcess implements WorkflowProcess
{
    public const KEY = 'role-handover';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'self-service';
    }

    public function name(): string
    {
        return 'Role handover';
    }

    public function description(): string
    {
        return 'An employee hands their role (e.g. head of department) to a colleague for a period. Handovers made with a leave request follow the leave approval instead.';
    }

    /** @param RoleHandover $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing('role', 'fromUser', 'toUser');

        return [
            'title' => ($subject->fromUser?->display_name ?? 'Employee').' hands over '.$subject->role?->name.' to '.($subject->toUser?->display_name ?? 'a colleague'),
            'lines' => array_values(array_filter([
                'From '.$subject->starts_at->format('d M Y').' to '.($subject->ends_at?->copy()->subDay()->format('d M Y') ?? 'further notice'),
                $subject->suspend_giver ? 'The giver cannot use the role meanwhile' : 'Both can use the role meanwhile',
                'Reason: '.$subject->reason,
            ])),
            'link' => null,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->fromUser?->employee?->company_branch_id ?? $subject->fromUser?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->fromUser?->employee?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->fromUser?->employee?->id;
    }

    public function onApproved(Model $subject): void
    {
        app(Handovers::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(Handovers::class)->reject($subject, $comment);
    }
}
