<?php

namespace Modules\Core\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your ' . config('app.name') . ' password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.reset-password', with: [
            'minutes' => (int) config('auth.passwords.users.expire'),
        ]);
    }
}
