<?php

namespace Modules\Admin\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Actions\ChangeUserAccess;
use Modules\Admin\Models\AccessChange;
use Modules\Workflow\Contracts\WorkflowProcess;

/** Approval of a change to a user's roles, direct permissions or activation. */
class UserAccessChangeProcess implements WorkflowProcess
{
    public function key(): string
    {
        return 'user-access-change';
    }

    public function module(): string
    {
        return 'admin';
    }

    public function name(): string
    {
        return 'User access change';
    }

    public function description(): string
    {
        return 'Changing a user\'s roles or direct permissions, or deactivating / reactivating a user.';
    }

    /** @param AccessChange $subject */
    public function summary(Model $subject): array
    {
        $user = $subject->user;
        $lines = match ($subject->type) {
            'roles', 'permissions' => array_values(array_filter([
                ($added = array_diff($subject->after ?? [], $subject->before ?? [])) ? 'Add: ' . implode(', ', $added) : null,
                ($removed = array_diff($subject->before ?? [], $subject->after ?? [])) ? 'Remove: ' . implode(', ', $removed) : null,
            ])),
            default => [],
        };
        if ($subject->reason) {
            $lines[] = 'Reason: ' . $subject->reason;
        }
        $what = ['roles' => 'Change roles of', 'permissions' => 'Change direct permissions of', 'deactivate' => 'Deactivate', 'activate' => 'Reactivate'][$subject->type] ?? 'Change access of';

        return ['title' => "{$what} {$user?->display_name}", 'lines' => $lines, 'link' => $user ? "/admin/users/{$user->id}" : null];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->user?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->user?->employee?->department_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ChangeUserAccess::class)->apply($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        $subject->update(['status' => AccessChange::REJECTED]);
    }
}
