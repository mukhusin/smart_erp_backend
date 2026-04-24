<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractStatusCommand extends Command
{
    protected $signature   = 'contract:status';
    protected $description = 'Mark expired contracts inactive and update employee contract_status';

    public function handle(): int
    {
        $today = Carbon::today();

        // Expire active contracts whose end_date has passed
        $expired = EmployeeContract::where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<', $today)
            ->get();

        $count = 0;
        foreach ($expired as $contract) {
            DB::transaction(function () use ($contract) {
                $contract->update(['is_active' => false, 'status' => 'expired']);
                // Update employee record only if this was their current contract
                Employee::where('id', $contract->employee_id)
                    ->where('contract_id', $contract->id)
                    ->update(['contract_status' => 'expired']);
            });
            $count++;
        }

        // Activate contracts whose start_date is today and are not yet active
        $activating = EmployeeContract::where('is_active', false)
            ->where('status', 'pending')
            ->where('start_date', '<=', $today)
            ->whereNull('end_date')
            ->orWhere(fn($q) => $q->where('is_active', false)
                ->where('status', 'pending')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today))
            ->get();

        foreach ($activating as $contract) {
            DB::transaction(function () use ($contract) {
                $contract->update(['is_active' => true, 'status' => 'active']);
                Employee::where('id', $contract->employee_id)
                    ->update(['contract_id' => $contract->id, 'contract_status' => 'active']);
            });
        }

        $this->info("Contracts processed: {$count} expired, {$activating->count()} activated.");
        Log::info("contract:status — expired: {$count}, activated: {$activating->count()}");

        return self::SUCCESS;
    }
}
