<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Hr\Actions\ManageAllowances;

class AllowanceGenerateCommand extends Command
{
    protected $signature = 'allowance:generate {--days=7 : How many past days to work out again}';

    protected $description = 'Work out automatic allowances from the roster and working hours (the last days, to follow roster changes)';

    public function handle(CompanyContext $context, ManageAllowances $allowances): int
    {
        $added = $removed = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $r = $context->run($companyId, fn () => $allowances->generate(now()->subDays((int) $this->option('days')), now()));
            $added += $r['added'];
            $removed += $r['removed'];
        }
        $this->info("Allowances added: {$added}, removed: {$removed}");

        return self::SUCCESS;
    }
}
