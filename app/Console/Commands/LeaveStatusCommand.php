<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Actions\ManageAnnualRoster;
use Modules\Hr\Actions\ManageLeave;

class LeaveStatusCommand extends Command
{
    protected $signature = 'leave:status';

    protected $description = 'Mark approved leave that has started as on leave and ended leave as reported back; remind employees of planned leave';

    public function handle(CompanyContext $context, ManageLeave $leave, ManageAnnualRoster $plans): int
    {
        $started = $ended = $reminded = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $result = $context->run($companyId, fn () => $leave->updateStatuses());
            $started += $result['started'];
            $ended += $result['ended'];
            $reminded += $context->run($companyId, fn () => $plans->remind());
        }
        $this->info("Leave started: {$started}, reported back: {$ended}, planned leave reminders: {$reminded}");

        return self::SUCCESS;
    }
}
