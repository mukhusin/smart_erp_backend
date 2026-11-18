<?php

namespace Modules\Workflow\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Workflow\Services\WorkflowActions;

class WorkflowOverdue extends Command
{
    protected $signature = 'workflow:overdue';

    protected $description = 'Remind or escalate approval steps waiting longer than their workflow allows.';

    public function handle(WorkflowActions $actions): int
    {
        foreach (Company::query()->get() as $company) {
            app(CompanyContext::class)->run($company, fn () => $actions->overdue());
        }

        return self::SUCCESS;
    }
}
