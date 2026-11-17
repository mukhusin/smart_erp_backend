<?php

namespace Modules\Procurement;

use Illuminate\Support\ServiceProvider;
use Modules\Procurement\Console\ProcurementJobs;

class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ProcurementJobs::class]);
        }
    }
}
