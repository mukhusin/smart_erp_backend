<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Hr\Actions\ManageContracts;

class ContractStatusCommand extends Command
{
    protected $signature = 'contract:status';

    protected $description = 'End expired contracts, start waiting ones and deactivate accounts of employees who have left';

    public function handle(CompanyContext $context, ManageContracts $contracts): int
    {
        $today = CarbonImmutable::today()->toDateString();
        $count = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            $count += $context->run($companyId, function () use ($contracts, $today) {
                $due = Employee::where(fn ($q) => $q
                    ->whereHas('contracts', fn ($q) => $q
                        ->where(fn ($q) => $q->where('is_active', true)->whereDate('end_date', '<', $today))
                        ->orWhere(fn ($q) => $q->where('status', 'waiting')->whereDate('start_date', '<=', $today)))
                    ->orWhere(fn ($q) => $q->where('employment_status', 'terminated')->whereDate('termination_date', '<=', $today)
                        ->whereHas('user', fn ($u) => $u->where('is_active', true))))
                    ->get();
                $due->each(fn (Employee $employee) => $contracts->sync($employee));

                return $due->count();
            });
        }

        $this->info("Employees with contract changes: {$count}");

        return self::SUCCESS;
    }
}
