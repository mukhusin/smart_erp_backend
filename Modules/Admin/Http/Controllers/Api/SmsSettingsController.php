<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySmsSetting;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Sms\PhoneNumber;
use Modules\Core\Sms\SmsService;

/**
 * @tags Admin: SMS
 */
class SmsSettingsController extends Controller
{
    /**
     * The company's SMS settings.
     *
     * `drivers` lists the available drivers and whether the server is set up for each.
     *
     * @response array{data: array{is_enabled: bool, driver: string, sender_id: string|null, contract_reminders: bool, drivers: list<array{value: string, label: string, configured: bool}>}}
     */
    public function show(SmsService $sms): JsonResponse
    {
        return response()->json(['data' => $this->payload($sms->settings(), $sms)]);
    }

    /**
     * Update the company's SMS settings.
     *
     * @response array{data: array{is_enabled: bool, driver: string, sender_id: string|null, contract_reminders: bool, drivers: list<array{value: string, label: string, configured: bool}>}}
     */
    public function update(Request $request, SmsService $sms): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            /** `log` (write to the log only) or `afyabox`. */
            'driver' => ['required', Rule::in(config('sms.drivers'))],
            /** Sender name shown on phones, up to 11 letters or digits. */
            'sender_id' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
            /** Remind employees by SMS when their contract ends within 90 days. */
            'contract_reminders' => ['required', 'boolean'],
        ], ['sender_id.regex' => 'Use letters and digits only.']);

        if ($data['is_enabled'] && ! $sms->driver($data['driver'])->isConfigured()) {
            abort(422, 'The server is not set up for this SMS driver yet. Ask the system administrator to configure it.');
        }

        $settings = CompanySmsSetting::current();
        $settings->fill($data)->save();

        return response()->json(['data' => $this->payload($settings, $sms)]);
    }

    /**
     * Send a test SMS through the chosen driver.
     *
     * @response array{message: string}
     */
    public function test(Request $request, SmsService $sms): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        abort_unless(PhoneNumber::tanzania($data['phone']), 422, 'Enter a Tanzanian mobile number, e.g. 0712 345 678.');

        $messaging = $sms->send('Test message', [['phone' => $data['phone'], 'message' => 'Test message from '.(app(CompanyContext::class)->get()?->name ?? 'Smart ERP').'.']], 'test', null, force: true);
        abort_if($messaging?->status === 'Failed', 503, 'The SMS could not be sent: '.$messaging->response);

        return response()->json(['message' => $sms->settings()->driver === 'log' ? 'Written to the server log (log driver).' : 'Test SMS queued.']);
    }

    private function payload(CompanySmsSetting $settings, SmsService $sms): array
    {
        $labels = ['log' => 'Log only (no SMS is sent)', 'afyabox' => 'AfyaBox'];

        return [
            'is_enabled' => (bool) $settings->is_enabled,
            'driver' => $settings->driver ?? 'log',
            'sender_id' => $settings->sender_id,
            'contract_reminders' => (bool) ($settings->contract_reminders ?? true),
            'drivers' => collect(config('sms.drivers'))->map(fn ($d) => ['value' => $d, 'label' => $labels[$d] ?? $d, 'configured' => $sms->driver($d)->isConfigured()])->all(),
        ];
    }
}
