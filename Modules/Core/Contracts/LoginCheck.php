<?php

namespace Modules\Core\Contracts;

use App\Models\User;

/** A module rule that can refuse a user's login (and end their session). Registered with the `login.checks` tag. */
interface LoginCheck
{
    /** Why the user may not log in, or null when they may. */
    public function deny(User $user): ?string;
}
