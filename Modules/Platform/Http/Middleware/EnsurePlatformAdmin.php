<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Platform routes are for the system owner only, never for company users. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isPlatformAdmin()) {
            return response()->json(['message' => 'Only the system administrator can do this.'], 403);
        }

        return $next($request);
    }
}
