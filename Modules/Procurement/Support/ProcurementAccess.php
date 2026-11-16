<?php

namespace Modules\Procurement\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;

class ProcurementAccess
{
    public function __construct(private readonly AccessService $access) {}

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, "procurement.{$permission}") || ($permission !== 'manage' && $this->access->can($user, 'procurement.manage'));
    }

    public function seesSuppliers(User $user): bool
    {
        return $this->can($user, 'view') || $this->can($user, 'suppliers') || $this->access->can($user, 'payments.vouchers') || $this->access->can($user, 'payments.manage');
    }
}
