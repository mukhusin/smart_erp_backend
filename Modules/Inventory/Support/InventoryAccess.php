<?php

namespace Modules\Inventory\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;
use Modules\Inventory\Models\Store;

class InventoryAccess
{
    public function __construct(private readonly AccessService $access) {}

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, "inventory.{$permission}") || ($permission !== 'manage' && $this->access->can($user, 'inventory.manage'));
    }

    public function sees(User $user): bool
    {
        return $this->can($user, 'view') || $this->can($user, 'catalogue') || $this->can($user, 'stores') || $this->access->can($user, 'procurement.view') || $this->access->can($user, 'procurement.manage');
    }

    public function role(User $user, int $storeId): ?string
    {
        if ($this->can($user, 'manage')) {
            return 'manager';
        }

        return \Illuminate\Support\Facades\DB::table('store_users')->where('store_id', $storeId)->where('user_id', $user->id)->value('role');
    }

    public function operates(User $user, int $storeId): bool
    {
        return $this->role($user, $storeId) !== null;
    }

    public function seesStock(User $user): bool
    {
        return $this->sees($user) || \Illuminate\Support\Facades\DB::table('store_users')->where('user_id', $user->id)->exists();
    }

    /** Own department for every staff member; heads see their scope; store staff and managers see all. */
    public function canRequest(User $user, int $departmentId): bool
    {
        if ($this->can($user, 'manage') || (int) $user->employee?->department_id === $departmentId) {
            return true;
        }
        $rules = app(\Modules\Hr\Support\EmployeeScope::class)->rules($user);

        return $this->access->can($user, 'inventory.request') && ($rules === null || in_array($departmentId, $rules['departments'], true));
    }

    /** @return list<int>|null store ids the user works in, null = all */
    public function stores(User $user): ?array
    {
        if ($this->can($user, 'manage') || $this->can($user, 'view')) {
            return null;
        }

        return Store::whereHas('users', fn ($q) => $q->where('users.id', $user->id))->pluck('id')->all();
    }
}
