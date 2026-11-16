<?php

namespace Modules\Budgeting\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;

class BudgetAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope) {}

    public function manages(User $user): bool
    {
        return $this->access->can($user, 'budgeting.manage');
    }

    public function seesAll(User $user): bool
    {
        return $this->manages($user) || $this->access->can($user, 'budgeting.view');
    }

    public function departments(User $user): ?array
    {
        if ($this->manages($user) || $this->access->can($user, 'budgeting.view')) {
            return null;
        }
        if (! $this->access->can($user, 'budgeting.prepare')) {
            return [];
        }
        $rules = $this->scope->rules($user);

        return $rules === null ? null : array_values(array_unique($rules['departments']));
    }

    public function canPrepare(User $user, int $departmentId): bool
    {
        if ($this->manages($user)) {
            return true;
        }
        if (! $this->access->can($user, 'budgeting.prepare')) {
            return false;
        }
        $rules = $this->scope->rules($user);

        return $rules === null || in_array($departmentId, $rules['departments'], true);
    }

    public function canView(User $user, int $departmentId): bool
    {
        $departments = $this->departments($user);

        return $departments === null || in_array($departmentId, $departments, true);
    }
}
