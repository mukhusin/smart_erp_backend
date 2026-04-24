<?php

namespace App\Console\Commands;

use App\Models\AnnualLeaveRoster;
use App\Models\EmpLeave;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class LeaveStatusCommand extends Command
{
    protected $signature   = 'leave:status';
    protected $description = 'Activate approved leaves that have started; mark ended active leaves as reported';

    public function handle(): int
    {
        $today    = Carbon::today();
        $activated = 0;
        $reported  = 0;

        // ── Other Leaves ──────────────────────────────────────────
        // Activate approved leaves whose start_date has arrived
        $toActivate = EmpLeave::where('status', 'approved')
            ->where('is_active', false)
            ->whereDate('requested_start_date', '<=', $today)
            ->whereDate('requested_end_date', '>=', $today)
            ->get();

        foreach ($toActivate as $leave) {
            $leave->update([
                'is_active'  => true,
                'status'     => 'active',
                'start_date' => $leave->requested_start_date,
                'end_date'   => $leave->requested_end_date,
            ]);
            $activated++;
        }

        // Mark active leaves as reported when end_date has passed
        $toReport = EmpLeave::where('status', 'active')
            ->where('is_active', true)
            ->whereDate('requested_end_date', '<', $today)
            ->get();

        foreach ($toReport as $leave) {
            $leave->update([
                'is_active' => false,
                'status'    => 'reported',
            ]);
            $reported++;
        }

        // ── Annual Leave Roster ───────────────────────────────────
        $alActivated = 0;
        $alReported  = 0;

        AnnualLeaveRoster::where('status', 'approved')
            ->where('is_active', false)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->each(function ($al) use (&$alActivated) {
                $al->update(['is_active' => true, 'status' => 'active']);
                $alActivated++;
            });

        AnnualLeaveRoster::where('status', 'active')
            ->where('is_active', true)
            ->whereDate('end_date', '<', $today)
            ->each(function ($al) use (&$alReported) {
                $al->update(['is_active' => false, 'status' => 'reported', 'is_reported' => true]);
                $alReported++;
            });

        $this->info("Other leaves — activated: {$activated}, reported: {$reported}");
        $this->info("Annual leaves — activated: {$alActivated}, reported: {$alReported}");
        Log::info("leave:status — leaves activated: {$activated}, reported: {$reported} | annual: activated: {$alActivated}, reported: {$alReported}");

        return self::SUCCESS;
    }
}
