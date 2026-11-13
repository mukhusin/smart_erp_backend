<?php

namespace Modules\Core\Sms;

use App\Models\CompanySmsSetting;
use App\Models\MessageRecipient;
use App\Models\Messaging;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends SMS for the current company through its chosen driver (Administration → SMS settings).
 * Every message is kept in `messagings` / `message_recipients` with its delivery status.
 */
class SmsService
{
    public function settings(): CompanySmsSetting
    {
        return CompanySmsSetting::current();
    }

    public function enabled(): bool
    {
        return $this->settings()->is_enabled;
    }

    public function driver(?string $name = null): SmsDriver
    {
        return match ($name ?? $this->settings()->driver) {
            'afyabox' => app(AfyaboxSmsDriver::class),
            default => app(LogSmsDriver::class),
        };
    }

    /**
     * @param  list<array{employee_id?: int|null, phone: string, message: string}>  $recipients
     * @return Messaging|null null when SMS is off or no recipient has a valid phone number
     */
    public function send(string $subject, array $recipients, ?string $campaign = null, ?string $campaignKey = null, bool $force = false): ?Messaging
    {
        $settings = $this->settings();
        if (! $force && ! $settings->is_enabled) {
            return null;
        }

        $valid = collect($recipients)->map(fn ($r) => ['phone' => PhoneNumber::tanzania($r['phone'])] + $r)->filter(fn ($r) => $r['phone']);
        if ($valid->isEmpty()) {
            return null;
        }

        $max = (int) config('sms.max_length', 120);
        $messaging = Messaging::create([
            'type' => Messaging::TYPE_SMS,
            'campaign' => $campaign,
            'campaign_key' => $campaignKey,
            'from_address' => $settings->sender_id,
            'subject' => Str::limit($subject, 120, ''),
            'message' => Str::limit($subject, 120, ''),
            'scheduled_at' => now(),
            'recipient_type' => Messaging::RECIPIENT_INDIVIDUAL,
            'created_by' => auth()->id(),
            'status' => Messaging::STATUS_PENDING,
        ]);
        foreach ($valid as $r) {
            $messaging->recipients()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $r['employee_id'] ?? null,
                'recipient_address' => $r['phone'],
                'individual_message' => mb_substr($r['message'], 0, $max),
                'status' => MessageRecipient::STATUS_PENDING,
            ]);
        }
        $this->deliver($messaging);

        return $messaging->refresh();
    }

    /** Pushes the pending recipients of a saved message to the company's driver and records the result. */
    public function deliver(Messaging $messaging): void
    {
        $messaging->load(['recipients' => fn ($q) => $q->where('status', MessageRecipient::STATUS_PENDING)]);

        try {
            $this->driver($this->settings()->driver)->push($messaging);
            $messaging->recipients()->where('status', MessageRecipient::STATUS_PENDING)->update(['status' => MessageRecipient::STATUS_SENT]);
            $messaging->update(['status' => Messaging::STATUS_SENT]);
        } catch (\Throwable $e) {
            Log::error('SMS sending failed', ['messaging_id' => $messaging->id, 'error' => $e->getMessage()]);
            $messaging->recipients()->where('status', MessageRecipient::STATUS_PENDING)->update(['status' => MessageRecipient::STATUS_FAILED, 'response' => Str::limit($e->getMessage(), 250)]);
            $messaging->update(['status' => Messaging::STATUS_FAILED, 'response' => Str::limit($e->getMessage(), 250)]);
        }
    }
}
