<?php

namespace App\Providers;

use App\Services\CompanyContext;
use App\Services\ModuleAccessService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Console\SyncPermissions;
use Modules\Core\Services\AccessService;
use Modules\Core\Support\PermissionCatalog;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class);
        $this->app->singleton(ModuleAccessService::class);
        $this->app->singleton(AccessService::class);
    }

    public function boot(): void
    {
        // Each module keeps its own migrations in Modules/<Module>/Database/Migrations.
        $this->loadMigrationsFrom(glob(base_path('Modules/*/Database/Migrations')));

        // Every permission check ($user->can(), @can, 'can:' / 'permission:' middleware, policies calling can)
        // goes through AccessService for catalog permissions. Other abilities fall through to policies.
        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && in_array($ability, PermissionCatalog::names(), true)) {
                return app(AccessService::class)->can($user, $ability);
            }

            return null;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissions::class]);
        }
    }
}
