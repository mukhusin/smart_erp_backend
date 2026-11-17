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
        $this->app->tag([\Modules\Hr\Support\EmployeeLoginCheck::class, \Modules\Audit\Support\AuditorLoginCheck::class], 'login.checks');
        $this->app->singleton(CompanyContext::class);
        $this->app->singleton(ModuleAccessService::class);
        $this->app->singleton(AccessService::class);
        $this->app->scoped(\App\Services\WorkCalendar::class);
        $this->app->scoped(\Modules\Hr\Support\LeavePeriods::class);
        $this->app->scoped(\Modules\Hr\Support\LeaveBalances::class);
    }

    private static function pushToDevice(\App\Models\Employee $employee): void
    {
        if (\App\Models\AuditLog::paused() || ! $employee->company_id) {
            return;
        }
        $uses = app(\App\Services\CompanyContext::class)->run($employee->company_id, fn () => \App\Models\AttendanceSetting::current()->enabled());
        if ($uses) {
            \Modules\Hr\Jobs\PushStaffToDeviceJob::dispatch($employee->company_id, $employee->id)->afterCommit();
        }
    }

    public function boot(): void
    {
        \App\Models\Employee::updated(function (\App\Models\Employee $employee) {
            if ($employee->department_id && $employee->wasChanged(['department_id', 'section_id'])) {
                app(\Modules\Hr\Actions\ManageAnnualRoster::class)->follow($employee);
            }
            if ($employee->has_attendance && $employee->wasChanged(['has_attendance', 'department_id', 'section_id', 'job_position_id', 'fname', 'mname', 'lname', 'gender', 'employment_status'])) {
                self::pushToDevice($employee);
            }
        });
        \App\Models\Employee::created(fn (\App\Models\Employee $employee) => $employee->has_attendance ? self::pushToDevice($employee) : null);
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
