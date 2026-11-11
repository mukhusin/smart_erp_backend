<?php

namespace App\Http\Middleware;

use App\Services\SingleDeviceSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Modules\Core\Support\LoginChecks;

class EnsureSessionTimeout
{
    public function handle(Request $request, Closure $next)
    {
        // Token (non-browser) requests have no session; single-device and idle checks apply to the SPA only.
        if (! $request->hasSession()) {
            return $next($request);
        }

        $timeout = config('session.lifetime') * 60;

        if (Auth::guard('web')->check()) {
            $user             = Auth::guard('web')->user();
            $currentSessionId = session()->getId();
            $sessionManager   = app(SingleDeviceSessionManager::class);

            if (! $sessionManager->validateSession($user->id, $currentSessionId)) {
                $sessionManager->clearSessionRegistration($user->id);
                Auth::guard('web')->logout();
                Session::flush();
                Session::regenerate();
                return response()->json([
                    'message' => 'Your account was accessed from another device. Please log in again.',
                    'code'    => 'session_replaced',
                ], 401);
            }

            if (time() - Session::get('last_activity', time()) > $timeout) {
                Auth::guard('web')->logout();
                Session::flush();
                Session::regenerate();
                return response()->json([
                    'message' => 'Your session expired due to inactivity. Please log in again.',
                    'code'    => 'session_expired',
                ], 401);
            }

            if (time() - Session::get('login_checked_at', 0) > LoginChecks::RECHECK_MINUTES * 60) {
                if ($reason = app(LoginChecks::class)->deny($user)) {
                    $sessionManager->clearSessionRegistration($user->id);
                    Auth::guard('web')->logout();
                    Session::flush();
                    Session::regenerate();

                    return response()->json(['message' => $reason, 'code' => 'access_ended'], 401);
                }
                Session::put('login_checked_at', time());
            }

            Session::put('last_activity', time());
        }

        return $next($request);
    }
}
