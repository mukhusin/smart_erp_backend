<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── ERP Scheduled Commands ────────────────────────────────────────────────────
Schedule::command('contract:status')->everyTwoHours()
    ->appendOutputTo(storage_path('logs/contract-status.log'));

Schedule::command('leave:status')->everyFourHours()
    ->appendOutputTo(storage_path('logs/leave-status.log'));

Schedule::command('leave:annual-balance')->everySixHours()
    ->appendOutputTo(storage_path('logs/leave-annual-balance.log'));
// Cron entry: * * * * * php /path/to/smart-erp/artisan schedule:run >> /dev/null 2>&1
