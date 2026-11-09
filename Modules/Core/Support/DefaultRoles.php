<?php

namespace Modules\Core\Support;

use App\Models\Company;
use App\Services\CompanyContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The one place that defines permissions (global) and the roles every new company starts with.
 * Roles are per company (Spatie teams), so each company can later change its own roles.
 */
final class DefaultRoles
{
    public const PERMISSIONS = [
        // Organization
        'manage-branches', 'manage-departments', 'manage-settings',
        // People
        'manage-employees', 'manage-contracts',
        // Leave
        'manage-leave-types', 'approve-leave-hod', 'approve-leave-hr',
        // Payroll and time
        'manage-payroll', 'manage-rosters',
        // Access
        'manage-users', 'manage-roles',
        // Reports and self-service
        'view-reports', 'self-service',
    ];

    /** Role name => permissions ('*' = all). */
    public const ROLES = [
        'company-admin' => '*',
        'hr-officer' => [
            'manage-employees', 'manage-contracts', 'manage-departments', 'manage-leave-types',
            'approve-leave-hod', 'approve-leave-hr', 'manage-payroll', 'manage-rosters', 'view-reports', 'self-service',
        ],
        'hod' => ['approve-leave-hod', 'manage-rosters', 'self-service'],
        'employee' => ['self-service'],
    ];

    public const COMPANY_ADMIN = 'company-admin';

    public static function syncPermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function createForCompany(Company $company): void
    {
        self::syncPermissions();

        app(CompanyContext::class)->run($company, function () {
            foreach (self::ROLES as $name => $permissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($permissions === '*' ? self::PERMISSIONS : $permissions);
            }
        });
    }
}
