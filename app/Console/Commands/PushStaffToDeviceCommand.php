<?php

namespace App\Console\Commands;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Jobs\PushStaffToDeviceJob;

class PushStaffToDeviceCommand extends Command
{
    protected $signature = 'attendance:push-staff';

    protected $description = 'Queue registering every current employee with attendance on the fingerprint devices, for companies that use BioTime';

    public function handle(CompanyContext $context): int
    {
        $queued = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            if ($context->run($companyId, fn () => AttendanceSetting::current()->enabled())) {
                PushStaffToDeviceJob::dispatch($companyId);
                $queued++;
            }
        }
        $this->info("Companies queued: {$queued}");

        return self::SUCCESS;
    }
}
