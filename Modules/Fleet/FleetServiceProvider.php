<?php

namespace Modules\Fleet;

use Illuminate\Support\ServiceProvider;
use Modules\Fleet\Console\FleetJobs;

class FleetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FleetJobs::class]);
        }
    }
}
