<?php

namespace Modules\Audit\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Modules\Audit\Support\Auditors;

/**
 * Auditors are read-only: no create, change, approve or delete anywhere, except their own self service, audit
 * queries and their account. Staff medical documents and dependants stay hidden. External auditors stop at their
 * access end date. Every page an auditor opens is written to the audit log.
 */
class AuditorGuard
{
    private const WRITE_ALLOWED = ['auth.', 'me.', 'self.', 'audit.queries.'];

    private const HIDDEN = ['.documents.', '.dependants.'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! Auditors::is($user)) {
            return $next($request);
        }
        $name = (string) $request->route()?->getName();
        if (Auditors::external($user) && $user->access_expires_at && $user->access_expires_at->isPast() && $name !== 'auth.logout') {
            auth()->guard('web')->logout();

            return response()->json(['message' => 'Your audit access ended on '.$user->access_expires_at->format('d M Y').'.', 'code' => 'audit_access_ended'], 401);
        }
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! collect(self::WRITE_ALLOWED)->contains(fn ($p) => str_starts_with($name, $p))) {
            return response()->json(['message' => 'Auditors have read-only access.', 'code' => 'auditor_read_only'], 403);
        }
        if (str_starts_with($name, 'hr.') && collect(self::HIDDEN)->contains(fn ($p) => str_contains($name, $p))) {
            return response()->json(['message' => 'Staff documents and dependants are not part of the audit access.', 'code' => 'auditor_hidden'], 403);
        }
        $response = $next($request);
        if ($request->isMethod('GET') && $name !== '' && ! str_starts_with($name, 'me')) {
            AuditLog::create(['company_id' => $user->company_id, 'auditable_type' => 'page', 'auditable_id' => 0, 'action' => 'viewed', 'user_id' => $user->id,
                'employee_id' => $user->employee?->id, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'url' => mb_substr($request->getRequestUri(), 0, 500), 'new_values' => ['route' => $name, 'status' => $response->getStatusCode()]]);
        }

        return $response;
    }
}
