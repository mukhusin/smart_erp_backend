<?php

namespace Modules\Workflow;

use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Console\WorkflowOverdue;

class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WorkflowOverdue::class]);
        }
    }
}
