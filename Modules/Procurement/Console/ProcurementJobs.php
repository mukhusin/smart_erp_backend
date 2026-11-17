<?php

namespace Modules\Procurement\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Procurement\Actions\ManageTenders;

class ProcurementJobs extends Command
{
    protected $signature = 'procurement:daily';

    protected $description = 'End tender standstill periods that ran out and warn about contracts and securities about to expire.';

    public function handle(ManageTenders $tenders, ManageContracts $contracts): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'procurement')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, function () use ($tenders, $contracts) {
                $tenders->closeStandstills();
                $contracts->warn();
            });
        }

        return self::SUCCESS;
    }
}
