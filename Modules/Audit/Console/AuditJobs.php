<?php

namespace Modules\Audit\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Audit\Actions\ManageEngagements;

class AuditJobs extends Command
{
    protected $signature = 'audit:daily';

    protected $description = 'Switch off external auditor accounts whose access period has ended.';

    public function handle(ManageEngagements $engagements): int
    {
        foreach (Company::query()->get() as $company) {
            app(CompanyContext::class)->run($company, fn () => $engagements->expire());
        }

        return self::SUCCESS;
    }
}
