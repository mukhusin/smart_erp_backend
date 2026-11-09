<?php

namespace App\Providers;

use App\Services\CompanyContext;
use App\Services\ModuleAccessService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class);
        $this->app->singleton(ModuleAccessService::class);
    }

    public function boot(): void
    {
        //
    }
}
