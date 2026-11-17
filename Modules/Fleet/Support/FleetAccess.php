<?php

namespace Modules\Fleet\Support;

use App\Models\User;
use Modules\Core\Services\AccessService;

class FleetAccess
{
    public function __construct(private readonly AccessService $access) {}

    public function manages(User $user): bool
    {
        return $this->access->can($user, 'fleet.manage');
    }

    public function sees(User $user): bool
    {
        return $this->manages($user) || $this->access->can($user, 'fleet.view');
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function managers()
    {
        return User::where('company_id', app(\App\Services\CompanyContext::class)->id())->where('is_active', true)->get()->filter(fn (User $u) => $this->manages($u))->values();
    }
}
