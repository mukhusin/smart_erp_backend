<?php

namespace Modules\Budgeting;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Events\JournalPosted;
use Modules\Budgeting\Console\CheckBudgetBalances;
use Modules\Budgeting\Console\SeedFinanceDemo;
use Modules\Budgeting\Listeners\RecordSpending;

class BudgetingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(JournalPosted::class, RecordSpending::class);
        if ($this->app->runningInConsole()) {
            $this->commands([CheckBudgetBalances::class, SeedFinanceDemo::class]);
        }
    }
}
