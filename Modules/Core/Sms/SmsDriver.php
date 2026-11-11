<?php

namespace Modules\Core\Sms;

use App\Models\Messaging;

interface SmsDriver
{
    /** Hand the message's pending recipients to the gateway. Throws when the gateway cannot be reached. */
    public function push(Messaging $messaging): void;

    /** Whether the server has what this driver needs (e.g. the AfyaBox connection). */
    public function isConfigured(): bool;
}
