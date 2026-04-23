<?php

namespace App\Http\Middleware;

use App\Services\SingleDeviceSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class EnsureSessionTimeout
{
    public function handle(Request $request, Closure $next)
    {
        $timeout = config('session.lifetime') * 60;

        if (Auth::check()) {
            $user             = Auth::user();
            $currentSessionId = session()->getId();
            $sessionManager   = app(SingleDeviceSessionManager::class);

            if (! $sessionManager->validateSession($user->id, $currentSessionId)) {
                $sessionManager->clearSessionRegistration($user->id);
                Auth::logout();
                Session::flush();
                Session::regenerate();
                return redirect()->route('login')
                    ->with('warning', 'Your account was accessed from another device. Please log in again.');
            }

            if (time() - Session::get('last_activity', time()) > $timeout) {
                Auth::logout();
                Session::flush();
                Session::regenerate();
                return redirect()->route('login')
                    ->with('warning', 'Your session expired due to inactivity. Please log in again.');
            }

            Session::put('last_activity', time());
        }

        return $next($request);
    }
}
