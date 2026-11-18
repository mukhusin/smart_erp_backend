<?php

namespace Modules\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** An in-app notice for a user (e.g. "Contract renewed", "Leave approved"). */
class Notice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $message, public readonly ?string $link = null, public readonly string $icon = 'bell', public readonly ?string $expiresAt = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_filter(['title' => $this->title, 'message' => $this->message, 'link' => $this->link, 'icon' => $this->icon, 'expires_at' => $this->expiresAt], fn ($v) => $v !== null) + ['link' => null];
    }
}
