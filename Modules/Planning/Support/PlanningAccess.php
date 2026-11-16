<?php

namespace Modules\Planning\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;

class PlanningAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope) {}

    public function manages(User $user): bool
    {
        return $this->access->can($user, 'planning.manage');
    }

    public function seesAll(User $user): bool
    {
        return $this->manages($user) || $this->access->can($user, 'planning.view');
    }

    public function departments(User $user): ?array
    {
        if ($this->manages($user)) {
            return null;
        }
        if (! $this->access->can($user, 'planning.department')) {
            return [];
        }
        $rules = $this->scope->rules($user);

        return $rules === null ? null : array_values(array_unique($rules['departments']));
    }

    public function canPlan(User $user, int $departmentId): bool
    {
        $departments = $this->departments($user);

        return $departments === null ? ($this->manages($user) || $this->access->can($user, 'planning.department')) : in_array($departmentId, $departments, true);
    }

    public function canView(User $user, int $departmentId): bool
    {
        return $this->seesAll($user) || $this->canPlan($user, $departmentId);
    }
}
