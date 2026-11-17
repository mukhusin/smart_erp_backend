<?php

namespace Modules\Fleet\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Fleet\Actions\FleetAlerts;

class FleetJobs extends Command
{
    protected $signature = 'fleet:daily';

    protected $description = 'End driver assignments that ran out, close units whose asset was disposed, and remind about expiring insurance, licences and certificates.';

    public function handle(FleetAlerts $alerts): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'fleet')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, fn () => $alerts->run());
        }

        return self::SUCCESS;
    }
}
