<?php

namespace App\Console\Commands;

use App\Models\AnnualLeaveRoster;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class LeaveAnnualBalanceCommand extends Command
{
    protected $signature   = 'leave:annual-balance';
    protected $description = 'Recalculate actual annual leave balances for active roster entries';

    public function handle(): int
    {
        $year = Carbon::now()->year;
        $updated = 0;

        // For each active annual leave roster entry in current year,
        // compute days used vs balance
        $entries = AnnualLeaveRoster::where('leave_year', $year)
            ->whereIn('status', ['approved', 'active', 'hod_approved'])
            ->get();

        foreach ($entries as $entry) {
            $usedDays = AnnualLeaveRoster::where('employee_id', $entry->employee_id)
                ->where('leave_year', $year)
                ->where('status', 'reported')
                ->sum('days');

            $actualBalance = ($entry->current_balance ?? 0) - $usedDays;

            $entry->update(['actual_balance' => max(0, $actualBalance)]);
            $updated++;
        }

        $this->info("Annual leave balances updated for {$updated} entries (year: {$year}).");
        Log::info("leave:annual-balance — updated: {$updated} entries for year {$year}");

        return self::SUCCESS;
    }
}
