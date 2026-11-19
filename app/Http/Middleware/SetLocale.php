<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;

/** Each request runs in the user's language; before login, in the language the browser asks for (X-Locale or Accept-Language). */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $asked = $request->header('X-Locale') ?: $request->getPreferredLanguage(array_keys(Locale::SUPPORTED));
        app()->setLocale($user ? Locale::for($user) : (array_key_exists((string) $asked, Locale::SUPPORTED) ? $asked : 'en'));

        return $next($request);
    }
}
