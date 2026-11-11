<?php

namespace Modules\Core\Support;

use App\Models\User;
use Modules\Core\Contracts\LoginCheck;

final class LoginChecks
{
    /** Minutes between re-checks of a logged-in session. */
    public const RECHECK_MINUTES = 15;

    public function deny(User $user): ?string
    {
        if ($user->isPlatformAdmin()) {
            return null;
        }
        /** @var LoginCheck $check */
        foreach (app()->tagged('login.checks') as $check) {
            if ($reason = $check->deny($user)) {
                return $reason;
            }
        }

        return null;
    }
}
