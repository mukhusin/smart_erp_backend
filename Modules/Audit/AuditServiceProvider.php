<?php

namespace Modules\Audit;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Console\AuditJobs;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Builder::macro('inAuditPeriod', function (string $column) {
            $period = \Modules\Audit\Support\Auditors::period(auth()->user());

            return $period ? $this->whereDate($column, '>=', $period[0])->whereDate($column, '<=', $period[1]) : $this;
        });
        if ($this->app->runningInConsole()) {
            $this->commands([AuditJobs::class]);
        }
    }
}
