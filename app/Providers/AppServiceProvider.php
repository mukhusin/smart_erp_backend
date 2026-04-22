<?php

namespace App\Providers;

use App\Services\CompanyContext;
use App\Services\ModuleAccessService;
use Illuminate\Support\Facades\View;
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
        View::composer('*', function ($view) {
            if (auth()->check()) {
                $context = app(CompanyContext::class);
                $view->with('currentCompany', $context->get());
                $view->with('erpModules', app(ModuleAccessService::class)->allForDashboard());
            }
        });
    }
}
