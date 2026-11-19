<?php

namespace Modules\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An in-app notice for a user (e.g. "Contract renewed", "Leave approved"). Title and message are English templates with
 * :placeholders filled from `params`; they are stored as English and shown in each reader's language.
 */
class Notice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $message, public readonly ?string $link = null, public readonly string $icon = 'bell', public readonly ?string $expiresAt = null, public readonly array $params = []) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'title' => trans($this->title, $this->params, 'en'), 'message' => trans($this->message, $this->params, 'en'), 'link' => $this->link, 'icon' => $this->icon,
            'expires_at' => $this->expiresAt, 't' => ['title' => $this->title, 'message' => $this->message, 'params' => $this->params],
        ], fn ($v) => $v !== null) + ['link' => null];
    }
}
