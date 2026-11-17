<?php

namespace Modules\Audit\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Audit\Models\AuditEngagement;

/** Who is an auditor, and what an external auditor's engagement allows. Auditors are read-only everywhere. */
final class Auditors
{
    public const INTERNAL = 'internal-auditor';

    public const EXTERNAL = 'external-auditor';

    public static function is(?User $user): bool
    {
        return $user !== null && $user->company_id !== null && ($user->hasRole(self::INTERNAL) || $user->hasRole(self::EXTERNAL));
    }

    public static function external(?User $user): bool
    {
        return $user !== null && $user->company_id !== null && $user->hasRole(self::EXTERNAL);
    }

    public static function engagement(User $user): ?AuditEngagement
    {
        return AuditEngagement::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'active')->latest('id')->first();
    }

    /** @return array{0: string, 1: string}|null the audited period of an external auditor */
    public static function period(?User $user): ?array
    {
        if (! self::external($user)) {
            return null;
        }
        $e = self::engagement($user);

        return $e ? [$e->period_from->toDateString(), $e->period_to->toDateString()] : ['1900-01-01', '1900-01-01'];
    }

    /** Keeps a date range inside the audited period for external auditors (others unchanged). @return array{0: ?string, 1: ?string} */
    public static function clamp(?User $user, ?string $from, ?string $to): array
    {
        $p = self::period($user);
        if (! $p) {
            return [$from, $to];
        }
        $f = $from ? max($from, $p[0]) : $p[0];
        $t = $to ? min($to, $p[1]) : $p[1];

        return [$f, Carbon::parse($t)->lt(Carbon::parse($f)) ? $f : $t];
    }
}
