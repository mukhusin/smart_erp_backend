<?php

namespace Modules\Core\Sms;

use App\Models\Messaging;
use Illuminate\Support\Facades\Log;

/** Writes messages to the log instead of sending them (development, or before a provider is set up). */
class LogSmsDriver implements SmsDriver
{
    public function push(Messaging $messaging): void
    {
        foreach ($messaging->recipients as $recipient) {
            Log::info("SMS to {$recipient->recipient_address}: ".($recipient->individual_message ?? $messaging->message));
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
