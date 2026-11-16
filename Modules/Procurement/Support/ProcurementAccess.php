<?php

namespace Modules\Procurement\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;

class ProcurementAccess
{
    public function __construct(private readonly AccessService $access, private readonly \Modules\Hr\Support\EmployeeScope $scope) {}

    public function buyer(User $user): bool
    {
        return $this->can($user, 'orders');
    }

    public function sees(User $user): bool
    {
        return $this->can($user, 'view') || $this->can($user, 'orders') || $this->can($user, 'receive') || $this->access->can($user, 'payments.vouchers');
    }

    /** @return list<int>|null departments whose requisitions the user sees (null = all) */
    public function departments(User $user): ?array
    {
        if ($this->sees($user)) {
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

    public function canReceive(User $user, ?int $storeId): bool
    {
        return $this->can($user, 'receive') || $this->access->can($user, 'inventory.manage')
            || ($storeId && \Modules\Inventory\Models\Store::whereKey($storeId)->whereHas('users', fn ($q) => $q->where('users.id', $user->id))->exists());
    }

    public function canInvoice(User $user): bool
    {
        return $this->access->can($user, 'payments.vouchers') || $this->access->can($user, 'payments.manage') || $this->can($user, 'manage');
    }

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, "procurement.{$permission}") || ($permission !== 'manage' && $this->access->can($user, 'procurement.manage'));
    }

    public function seesSuppliers(User $user): bool
    {
        return $this->can($user, 'view') || $this->can($user, 'suppliers') || $this->access->can($user, 'payments.vouchers') || $this->access->can($user, 'payments.manage');
    }
}
