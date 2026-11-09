<?php

namespace Modules\Core\Actions\Auth;

use App\Models\User;
use App\Services\SingleDeviceSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session (cookie) login for the SPA. Accepts username or email, only active users,
 * and keeps the single-device rule: a new login ends the user's previous session.
 */
class Login
{
    public function __construct(private readonly SingleDeviceSessionManager $sessions) {}

    public function handle(Request $request, string $login, string $password): User
    {
        $login = trim($login);

        foreach ($this->credentialAttempts($login, $password) as $credentials) {
            if (Auth::guard('web')->attempt($credentials)) {
                $request->session()->regenerate();
                $this->sessions->registerLogin(Auth::id(), $request->session()->getId());
                $request->session()->put('last_activity', time());

                return Auth::user();
            }
        }

        Log::warning('Failed login attempt', ['username' => $login, 'ip' => $request->ip()]);

        throw ValidationException::withMessages([
            'username' => 'Wrong username / email or password, or account is inactive.',
        ]);
    }

    private function credentialAttempts(string $login, string $password): array
    {
        $base = ['password' => $password, 'isActive' => true];
        $byUsername = $base + ['username' => $login];
        $byEmail = $base + ['email' => Str::lower($login)];

        return filter_var($login, FILTER_VALIDATE_EMAIL) ? [$byEmail, $byUsername] : [$byUsername, $byEmail];
    }
}
