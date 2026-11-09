<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Core\Http\Requests\ForgotPasswordRequest;
use Modules\Core\Mail\ResetPasswordMail;

/**
 * @tags Auth
 */
class ForgotPasswordController extends Controller
{
    /**
     * Request a password reset link.
     *
     * Always answers 204, whether or not the email belongs to an account (so it cannot be used to find accounts).
     * Active users get an email with a link to choose a new password.
     *
     * @unauthenticated
     */
    public function __invoke(ForgotPasswordRequest $request): Response
    {
        $user = User::where('email', Str::lower($request->validated('email')))->where('is_active', true)->first();

        if ($user && ($user->isPlatformAdmin() || $user->company?->is_active)) {
            $broker = Password::broker('users');
            if (! $broker->getRepository()->recentlyCreatedToken($user)) {
                $token = $broker->createToken($user);
                $url = rtrim(config('app.frontend_url'), '/') . '/set-password?' . http_build_query([
                    'token' => $token, 'email' => $user->email, 'purpose' => 'reset',
                ]);
                try {
                    Mail::to($user->email)->send(new ResetPasswordMail($user, $url));
                } catch (\Throwable $e) {
                    Log::error('Password reset email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->noContent();
    }
}
