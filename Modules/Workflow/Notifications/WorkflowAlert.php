<?php

namespace Modules\Workflow\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A workflow action message, in the app and/or by email. */
class WorkflowAlert extends Notification
{
    use Queueable;

    /** @param list<string> $channels database and/or mail */
    public function __construct(public readonly string $title, public readonly string $message, public readonly ?string $link, public readonly array $channels) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter($this->channels, fn ($c) => $c !== 'mail' || ! empty($notifiable->email)));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'link' => $this->link, 'icon' => 'diagram-3'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->message);

        return $this->link ? $mail->action('Open', rtrim((string) config('app.frontend_url', config('app.url')), '/').$this->link) : $mail;
    }
}
