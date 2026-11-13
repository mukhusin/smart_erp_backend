<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Actions\ManagePayItems;

class SalaryAdjustmentsCommand extends Command
{
    protected $signature = 'hr:salary-adjustments';

    protected $description = 'Apply approved salary adjustments whose effective date has come';

    public function handle(CompanyContext $context, ManagePayItems $items): int
    {
        $applied = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $applied += $context->run($companyId, fn () => $items->applyDue());
        }
        $this->info("Salary adjustments applied: {$applied}");

        return self::SUCCESS;
    }
}
