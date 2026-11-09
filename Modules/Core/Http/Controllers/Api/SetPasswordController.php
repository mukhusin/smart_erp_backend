<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\SetPasswordRequest;

/**
 * @tags Auth
 */
class SetPasswordController extends Controller
{
    /**
     * Set password from an emailed link.
     *
     * For a new user's invite (e.g. a company's first HR admin) or a forgot-password link (`purpose: reset`).
     * After this, the user logs in normally.
     *
     * @unauthenticated
     */
    public function __invoke(SetPasswordRequest $request): Response
    {
        $status = Password::broker($request->validated('purpose') === 'reset' ? 'users' : 'invites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'This link is invalid or has expired. Request a new one.',
            ]);
        }

        return response()->noContent();
    }
}
