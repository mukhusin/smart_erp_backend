<?php

namespace Modules\Payments\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Payments\Actions\ManageRetirements;

class ImprestReminders extends Command
{
    protected $signature = 'payments:imprest-reminders';

    protected $description = 'Remind holders of imprests not retired by their date (the day after, then weekly).';

    public function handle(ManageRetirements $retirements): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'payments')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, fn () => $retirements->remind());
        }

        return self::SUCCESS;
    }
}
