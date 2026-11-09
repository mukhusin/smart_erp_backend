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
                /** @var User $user */
                $user = Auth::guard('web')->user();

                if (! $user->isPlatformAdmin() && ! $user->company?->is_active) {
                    Auth::guard('web')->logout();
                    throw ValidationException::withMessages([
                        'username' => 'Your organization\'s account is suspended. Contact the system administrator.',
                    ]);
                }

                $request->session()->regenerate();
                setPermissionsTeamId($user->company_id);
                $this->sessions->registerLogin(Auth::id(), $request->session()->getId());
                $request->session()->put('last_activity', time());

                return $user;
            }
        }

        Log::warning('Failed login attempt', ['username' => $login, 'ip' => $request->ip()]);

        throw ValidationException::withMessages([
            'username' => 'Wrong username / email or password, or account is inactive.',
        ]);
    }

    private function credentialAttempts(string $login, string $password): array
    {
        $base = ['password' => $password, 'is_active' => true];
        $byUsername = $base + ['username' => $login];
        $byEmail = $base + ['email' => Str::lower($login)];

        return filter_var($login, FILTER_VALIDATE_EMAIL) ? [$byEmail, $byUsername] : [$byUsername, $byEmail];
    }
}
