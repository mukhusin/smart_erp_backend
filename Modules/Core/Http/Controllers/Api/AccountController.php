<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\ChangeEmailRequest;
use Modules\Core\Http\Requests\ChangePasswordRequest;
use Modules\Core\Mail\EmailChangeCodeMail;
use Modules\Core\Notifications\Notice;

/**
 * The signed-in user's own password and email.
 *
 * @tags Me
 */
class AccountController extends Controller
{
    private const MINUTES = 15;

    private const ATTEMPTS = 5;

    /** Change my password (needs the current one). */
    public function password(ChangePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();
        $user->notify(new Notice('Password changed', 'Your password was changed. If it was not you, tell your administrator at once.', null, 'shield-lock'));

        return response()->noContent();
    }

    /**
     * Ask to change my email: a 6-digit code is sent to the new email.
     *
     * @status 202
     *
     * @response array{data: array{sent_to: string, expires_in_minutes: int}}
     */
    public function email(ChangeEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $email = strtolower(trim($request->validated('email')));
        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($user->id), ['email' => $email, 'code' => Hash::make($code), 'attempts' => 0], now()->addMinutes(self::MINUTES));
        Mail::to($email)->send(new EmailChangeCodeMail($code, $user->display_name));

        return response()->json(['data' => ['sent_to' => $email, 'expires_in_minutes' => self::MINUTES]], 202);
    }

    /**
     * Confirm my new email with the code; the employee record gets the same email.
     *
     * @response array{data: array{email: string}}
     */
    public function confirmEmail(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $user = $request->user();
        $pending = Cache::get($this->key($user->id));
        if (! $pending || $pending['attempts'] >= self::ATTEMPTS) {
            Cache::forget($this->key($user->id));
            throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one.']);
        }
        if (! Hash::check($code, $pending['code'])) {
            $pending['attempts']++;
            Cache::put($this->key($user->id), $pending, now()->addMinutes(self::MINUTES));
            throw ValidationException::withMessages(['code' => 'The code is not correct.']);
        }
        abort_if(User::where('email', $pending['email'])->whereKeyNot($user->id)->exists(), 422, 'Another account uses this email.');
        $old = $user->email;
        $user->forceFill(['email' => $pending['email'], 'email_verified_at' => now()])->save();
        Employee::withoutGlobalScopes()->where('user_id', $user->id)->update(['email' => $pending['email']]);
        Cache::forget($this->key($user->id));
        $user->notify(new Notice('Email changed', "Your account email changed from {$old} to {$pending['email']}.", null, 'envelope'));

        return response()->json(['data' => ['email' => $user->email]]);
    }

    private function key(int $userId): string
    {
        return "email-change:{$userId}";
    }
}
