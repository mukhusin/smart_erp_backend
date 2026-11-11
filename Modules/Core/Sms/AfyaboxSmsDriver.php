<?php

namespace Modules\Core\Sms;

use App\Models\MessageRecipient;
use App\Models\Messaging;
use Illuminate\Support\Facades\DB;

/** Queues messages in AfyaBox's `messaging` table (as HRMS does); AfyaBox delivers them. */
class AfyaboxSmsDriver implements SmsDriver
{
    public function push(Messaging $messaging): void
    {
        $now = now()->toDateTimeString();
        $rows = $messaging->recipients->map(fn (MessageRecipient $r) => [
            'uuid' => $r->uuid,
            'status' => MessageRecipient::STATUS_PENDING,
            'type' => Messaging::TYPE_SMS,
            'from_address' => $messaging->from_address,
            'to_address' => $r->recipient_address,
            'subject' => $messaging->subject,
            'message' => $r->individual_message ?? $messaging->message,
            'scheduled_at' => $messaging->scheduled_at?->toDateTimeString(),
            'created_at' => $now,
            'created_by' => $messaging->created_by,
            'updated_at' => $now,
            'updated_by' => $messaging->created_by,
        ])->all();

        DB::connection(config('sms.afyabox.connection'))->table(config('sms.afyabox.table'))
            ->upsert($rows, ['uuid'], ['status', 'type', 'from_address', 'to_address', 'subject', 'message', 'scheduled_at', 'updated_at', 'updated_by']);
    }

    public function isConfigured(): bool
    {
        $connection = config('database.connections.'.config('sms.afyabox.connection'));

        return ! empty($connection['host']) || ! empty($connection['url']);
    }
}
