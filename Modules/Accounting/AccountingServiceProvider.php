<?php

namespace Modules\Accounting;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Listeners\PostPayroll;
use Modules\Hr\Events\PayrollApproved;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PayrollApproved::class, PostPayroll::class);
        if ($this->app->runningInConsole()) {
            $this->commands([\Modules\Accounting\Console\BankFeeds::class]);
        }
    }
}
