<?php

namespace Modules\Receivables\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;

class ReceivableAccess
{
    public function __construct(private readonly AccessService $access) {}

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, "receivables.{$permission}") || ($permission !== 'manage' && $this->access->can($user, 'receivables.manage'));
    }

    public function sees(User $user): bool
    {
        return $this->can($user, 'view') || $this->can($user, 'bill') || $this->can($user, 'receipts');
    }
}
