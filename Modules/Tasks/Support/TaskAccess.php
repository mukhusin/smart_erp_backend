<?php

namespace Modules\Tasks\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Tasks\Models\Task;

class TaskAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope) {}

    public function canAssign(User $user): bool
    {
        return $this->access->can($user, 'tasks.assign');
    }

    public function seesAll(User $user): bool
    {
        return $this->access->can($user, 'tasks.view-all');
    }

    public function canAssignTo(User $user, Employee $employee): bool
    {
        if ($employee->user_id === $user->id) {
            return true;
        }

        return ($this->canAssign($user) || $this->seesAll($user)) && $this->scope->allows($user, $employee);
    }

    /** @param 'mine'|'team'|'all' $view */
    public function visible(Builder $query, User $user, string $view = 'mine'): Builder
    {
        $me = $user->employee?->id ?? 0;

        return match ($view) {
            'all' => $this->seesAll($user) ? $query : $query->whereRaw('1 = 0'),
            'team' => $this->canAssign($user) || $this->seesAll($user)
                ? $query->where(fn ($q) => $q->where('created_by', $user->id)->orWhereIn('employee_id', $this->scope->apply(Employee::query(), $user)->select('id')))->where('employee_id', '!=', $me)
                : $query->whereRaw('1 = 0'),
            default => $query->where('employee_id', $me),
        };
    }

    public function canView(User $user, Task $task): bool
    {
        return $task->employee?->user_id === $user->id || $task->created_by === $user->id || $this->seesAll($user)
            || ($this->canAssign($user) && $task->employee && $this->scope->allows($user, $task->employee));
    }

    public function canManage(User $user, Task $task): bool
    {
        return $task->created_by === $user->id || (($this->canAssign($user) || $this->seesAll($user)) && $task->employee && $task->employee->user_id !== $user->id && $this->scope->allows($user, $task->employee));
    }

    public function isAssignee(User $user, Task $task): bool
    {
        return $task->employee?->user_id === $user->id;
    }
}
