<?php

namespace App\Console\Commands;

use App\Models\AttendanceSetting;
use App\Models\AttendanceSyncDay;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Jobs\SyncAttendanceDayJob;

class AttendanceSyncCommand extends Command
{
    protected $signature = 'attendance:sync';

    protected $description = 'Queue biometric punch syncs for missing days (and today) for companies that use attendance';

    public function handle(CompanyContext $context): int
    {
        $queued = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $context->run($companyId, function () use ($companyId, &$queued) {
                $settings = AttendanceSetting::current();
                if (! $settings->enabled()) {
                    return;
                }
                for ($d = now()->subDays(max(1, $settings->sync_days))->startOfDay(); $d->lte(now()); $d->addDay()) {
                    $day = AttendanceSyncDay::for($d->toDateString());
                    $recent = $d->gte(now()->subDay()->startOfDay());
                    if ($recent || in_array($day->status, ['queued', 'failed'], true)) {
                        if (! $recent && $day->status === 'queued' && $day->attempts > 0 && $day->updated_at->gt(now()->subHours(2))) {
                            continue;
                        }
                        $day->update(['status' => 'queued']);
                        SyncAttendanceDayJob::dispatch($companyId, $d->toDateString());
                        $queued++;
                    }
                }
            });
        }
        $this->info("Attendance days queued: {$queued}");

        return self::SUCCESS;
    }
}
