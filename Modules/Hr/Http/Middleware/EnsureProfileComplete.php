<?php

namespace Modules\Hr\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Hr\Support\ProfileCompletion;

/** When HR requires it, staff below the profile percentage can only fill their profile; other changes are refused. */
class EnsureProfileComplete
{
    private const ALLOWED = ['auth.*', 'me', 'me.*', 'self.profile*', 'self.summary'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe() || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }
        $user = $request->user();
        if ($user && ($gate = ProfileCompletion::gate($user)) && ! $gate['complete']) {
            return response()->json([
                'message' => "Complete your profile first: it is {$gate['percent']}% filled and at least {$gate['min_percent']}% is required.",
                'profile_gate' => $gate,
            ], 423);
        }

        return $next($request);
    }
}
