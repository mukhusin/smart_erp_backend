<?php

namespace App\Http\Middleware;

use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Core\Services\AccessService;

/**
 * Per request: roles/permissions are read for the user's company (Spatie teams),
 * and users of a suspended company are stopped (they can still log out).
 */
class SetCompanyContext
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        app(AccessService::class)->forget();
        setPermissionsTeamId($this->context->id());

        $company = $this->context->get();
        if ($company && ! $company->is_active && ! $request->routeIs('auth.logout')) {
            return response()->json([
                'message' => 'Your organization\'s account is suspended. Contact the system administrator.',
                'code' => 'company_suspended',
            ], 403);
        }

        return $next($request);
    }
}
