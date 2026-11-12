<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Actions\ManageLeave;

class LeaveStatusCommand extends Command
{
    protected $signature = 'leave:status';

    protected $description = 'Mark approved leave that has started as on leave, and ended leave as reported back';

    public function handle(CompanyContext $context, ManageLeave $leave): int
    {
        $started = $ended = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $result = $context->run($companyId, fn () => $leave->updateStatuses());
            $started += $result['started'];
            $ended += $result['ended'];
        }
        $this->info("Leave started: {$started}, reported back: {$ended}");

        return self::SUCCESS;
    }
}
