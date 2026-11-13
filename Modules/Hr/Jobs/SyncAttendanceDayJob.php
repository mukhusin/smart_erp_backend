<?php

namespace Modules\Hr\Jobs;

use App\Models\AttendanceSetting;
use App\Models\AttendanceSyncDay;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\Hr\Actions\ManageAttendance;
use Modules\Hr\Attendance\PunchSources;

/**
 * Fetches one day of device punches, then works that day (and the day before, for night shifts) out again.
 * While the device server is down the day stays queued and the job retries every 30 minutes for up to 21 days.
 */
class SyncAttendanceDayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1008;

    public int $timeout = 900;

    public function __construct(public readonly int $companyId, public readonly string $date)
    {
        $this->onQueue('attendance');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(21);
    }

    public function handle(CompanyContext $context, ManageAttendance $attendance, PunchSources $sources): void
    {
        $context->run(Company::findOrFail($this->companyId), function () use ($attendance, $sources) {
            $day = AttendanceSyncDay::for($this->date);
            $source = $sources->forCurrentCompany();
            if (! $source) {
                $day->update(['status' => 'failed', 'last_error' => 'No device server is set up.']);

                return;
            }
            $day->update(['status' => 'fetching', 'attempts' => $day->attempts + 1]);
            if (! $source->reachable()) {
                $day->update(['status' => 'queued', 'last_error' => 'Device server not reachable; will retry.']);
                AttendanceSetting::query()->update(['last_error' => 'Device server not reachable at '.now()->format('d M Y H:i')]);
                $this->release(1800);

                return;
            }
            $date = Carbon::parse($this->date);
            $result = $attendance->addPunches($source->punches($date), 'device');
            $day->update(['status' => 'processing', 'punch_count' => $result['added'] + $result['skipped'], 'fetched_at' => now(), 'last_error' => null]);
            $attendance->compile($date->copy()->subDay(), $date);
            $day->update(['status' => 'processed', 'processed_at' => now()]);
            AttendanceSetting::query()->update(['last_synced_at' => now(), 'last_error' => null]);
        });
    }

    public function failed(\Throwable $e): void
    {
        app(CompanyContext::class)->run(Company::find($this->companyId), fn () => AttendanceSyncDay::whereDate('sync_date', $this->date)->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 480)]));
    }
}
