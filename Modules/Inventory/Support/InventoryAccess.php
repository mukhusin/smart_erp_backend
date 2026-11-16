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

    /** @return list<int>|null store ids the user works in, null = all */
    public function stores(User $user): ?array
    {
        if ($this->can($user, 'manage') || $this->can($user, 'view')) {
            return null;
        }

        return Store::whereHas('users', fn ($q) => $q->where('users.id', $user->id))->pluck('id')->all();
    }
}
