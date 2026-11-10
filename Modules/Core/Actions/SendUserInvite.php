<?php

namespace Modules\Core\Actions;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/** Emails a user a link to set their password (invites broker). Returns false if it could not be sent. */
class SendUserInvite
{
    /** @param callable(string $url): Mailable $mail */
    public function handle(User $user, callable $mail): bool
    {
        if (! $user->email) {
            return false;
        }

        try {
            Mail::to($user->email)->send($mail($this->link($user)));
            return true;
        } catch (\Throwable $e) {
            Log::error('Invite email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function link(User $user): string
    {
        $token = Password::broker('invites')->createToken($user);

        return rtrim(config('app.frontend_url'), '/') . '/set-password?' . http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);
    }
}
