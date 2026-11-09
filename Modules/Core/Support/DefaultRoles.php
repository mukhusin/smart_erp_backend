<?php

namespace Modules\Core\Support;

use App\Models\Company;
use App\Services\CompanyContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles every new company starts with. Roles are per company (Spatie teams):
 * each company can rename them, change their permissions or add its own.
 */
final class DefaultRoles
{
    public const COMPANY_ADMIN = 'company-admin';

    /** Role name => permission names ('*' = all), or module wildcards like 'leave.*'. */
    public const ROLES = [
        self::COMPANY_ADMIN => ['*'],
        'hr-officer' => [
            'hr.*', 'leave.*', 'roster.*', 'attendance.*', 'allowance.*', 'overtime.*', 'payroll.view',
            'payroll.create', 'payroll.edit', 'payroll.comment', 'loans.*', 'reports.*', 'self-service.use',
        ],
        'hod' => [
            'leave.department.view', 'leave.approve-hod', 'roster.department.view', 'roster.manage',
            'attendance.working-hours.department', 'allowance.department.view', 'overtime.view', 'self-service.use',
        ],
        'employee' => ['self-service.use'],
    ];

    /** Insert/update every permission in PermissionCatalog (module, label). */
    public static function syncPermissions(): void
    {
        foreach (PermissionCatalog::MODULES as $module => $permissions) {
            foreach ($permissions as $name => $label) {
                Permission::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['module_slug' => $module, 'label' => $label]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function createForCompany(Company $company): void
    {
        self::syncPermissions();

        app(CompanyContext::class)->run($company, function () {
            foreach (self::ROLES as $name => $patterns) {
                $role = Role::findOrCreate($name, 'web');
                $role->forceFill(['is_system' => true])->save();
                $role->syncPermissions(self::expand($patterns));
            }
        });
    }

    /** @param list<string> $patterns  @return list<string> */
    public static function expand(array $patterns): array
    {
        $all = PermissionCatalog::names();

        return array_values(array_unique(array_merge(...array_map(fn (string $p) => match (true) {
            $p === '*' => $all,
            str_ends_with($p, '.*') => array_values(array_filter($all, fn ($n) => str_starts_with($n, substr($p, 0, -1)))),
            default => [$p],
        }, $patterns))));
    }
}
