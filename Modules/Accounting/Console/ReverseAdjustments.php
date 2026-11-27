<?php

namespace Modules\Accounting\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Accounting\Actions\ManageJournals;

class ReverseAdjustments extends Command
{
    protected $signature = 'accounting:reverse-adjustments';

    protected $description = 'Reverse adjusting journals whose reversal date has come.';

    public function handle(ManageJournals $journals): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'accounting')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, function () use ($journals, $company) {
                $r = $journals->reverseDue();
                if ($r['reversed'] || $r['failed']) {
                    $this->line("{$company->name}: {$r['reversed']} reversed");
                }
                foreach ($r['failed'] as $f) {
                    $this->warn("{$company->name}: {$f}");
                }
            });
        }

        return self::SUCCESS;
    }
}
