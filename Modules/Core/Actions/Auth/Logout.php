<?php

namespace Modules\Core\Actions\Auth;

use App\Services\SingleDeviceSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class Logout
{
    public function __construct(private readonly SingleDeviceSessionManager $sessions) {}

    public function handle(Request $request): void
    {
        if ($user = $request->user()) {
            $this->sessions->clearSessionRegistration($user->id);
            // API token clients (future mobile app): revoke the token used. SPA cookie sessions have no stored token.
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
