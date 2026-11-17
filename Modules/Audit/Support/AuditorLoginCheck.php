<?php

namespace Modules\Audit\Support;

use App\Models\User;
use Modules\Core\Contracts\LoginCheck;

/** External auditors log in only during their access period. */
class AuditorLoginCheck implements LoginCheck
{
    public function deny(User $user): ?string
    {
        if (! $user->company_id) {
            return null;
        }
        setPermissionsTeamId($user->company_id);
        if (Auditors::external($user) && $user->access_expires_at && $user->access_expires_at->isPast()) {
            return 'Your audit access ended on '.$user->access_expires_at->format('d M Y').'.';
        }

        return null;
    }
}
