<?php

namespace Modules\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells the employee the outcome of their contract renewal (in the app). */
class RenewalDecided extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $message, public readonly string $link = '/self-service/contract') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'link' => $this->link, 'icon' => 'file-earmark-check'];
    }
}
