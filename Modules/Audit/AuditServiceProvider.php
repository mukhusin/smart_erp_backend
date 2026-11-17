<?php

namespace Modules\Audit;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Console\AuditJobs;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AuditJobs::class]);
        }
    }
}
