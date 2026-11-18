<?php

namespace Modules\Fleet\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Fleet\Actions\FleetAlerts;
use Modules\Fleet\Actions\ManageHires;
use Modules\Fleet\Actions\ManageMaintenance;

class FleetJobs extends Command
{
    protected $signature = 'fleet:daily';

    protected $description = 'End driver assignments that ran out, close units whose asset was disposed, remind about expiring insurance, licences and certificates, and open jobs for services that are due.';

    public function handle(FleetAlerts $alerts, ManageMaintenance $maintenance, ManageHires $hires): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'fleet')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, function () use ($alerts, $maintenance, $hires) {
                $hires->endDue();
                $alerts->run();
                $maintenance->autoServices();
            });
        }

        return self::SUCCESS;
    }
}
