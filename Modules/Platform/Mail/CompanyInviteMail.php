<?php

namespace Modules\Platform\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Company $company, public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->company->name} account on " . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.company-invite', with: [
            'days' => (int) ceil(config('auth.passwords.invites.expire') / 1440),
        ]);
    }
}
