<?php

namespace Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmailChangeCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-change-code');
    }
}
