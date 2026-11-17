<?php

namespace Modules\Assets\Support;

use App\Models\User;

class AssetAccess
{
    public function manages(User $user): bool
    {
        return $user->can('assets.manage');
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function officers()
    {
        return User::where('company_id', app(\App\Services\CompanyContext::class)->id())->where('is_active', true)->get()->filter(fn (User $u) => $u->can('assets.manage'))->values();
    }

    public function sees(User $user): bool
    {
        return $user->can('assets.view') || $user->can('assets.manage') || $user->can('accounting.view');
    }
}
