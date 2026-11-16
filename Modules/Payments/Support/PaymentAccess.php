<?php

namespace Modules\Payments\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;

class PaymentAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope) {}

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, "payments.{$permission}");
    }

    public function finance(User $user): bool
    {
        return $this->can($user, 'vouchers') || $this->can($user, 'manage');
    }

    public function cashier(User $user): bool
    {
        return $this->can($user, 'pay') || $this->can($user, 'manage');
    }

    public function seesAll(User $user): bool
    {
        return $this->finance($user) || $this->cashier($user) || $this->can($user, 'view');
    }

    /** @return list<int>|null null = every department */
    public function departments(User $user): ?array
    {
        if ($this->seesAll($user)) {
            return null;
        }
        if (! $this->can($user, 'request')) {
            return [];
        }
        $rules = $this->scope->rules($user);

        return $rules === null ? null : array_values(array_unique($rules['departments']));
    }

    public function canRequest(User $user, int $departmentId): bool
    {
        if ($this->can($user, 'manage')) {
            return true;
        }
        if (! $this->can($user, 'request')) {
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
