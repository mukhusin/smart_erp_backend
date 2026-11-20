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

    /** Roles given to department and section heads when HR appoints them. */
    public const HEAD_ROLES = ['hod' => 'hod', 'hos' => 'section-head'];

    /** Role name => permission names ('*' = all), or module wildcards like 'leave.*'. */
    public const ROLES = [
        self::COMPANY_ADMIN => ['admin.users.view', 'admin.users.manage', 'admin.roles.manage', 'admin.roles.assign', 'admin.handovers.manage', 'admin.module-locks.manage',
            'admin.branches.manage', 'admin.settings.manage', 'admin.workflow.manage', 'audit.engagements', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'internal-auditor' => ['audit.view', 'audit.queries', 'admin.audit.view', 'hr.employees.view', 'leave.view', 'roster.view', 'attendance.view', 'allowance.view', 'overtime.view',
            'payroll.view', 'loans.view', 'planning.view', 'budgeting.view', 'accounting.view', 'payments.view', 'receivables.view', 'procurement.view', 'inventory.view', 'assets.view', 'fleet.view',
            'appraisals.view', 'tasks.view-all', 'reports.view', 'reports.generate', 'self-service.use'],
        'external-auditor' => ['audit.view', 'audit.queries', 'accounting.view', 'budgeting.view', 'payments.view', 'receivables.view', 'procurement.view', 'inventory.view', 'assets.view', 'planning.view'],
        'hr-officer' => [
            'hr.*', 'leave.*', 'roster.*', 'attendance.*', 'allowance.*', 'overtime.*', 'payroll.view',
            'payroll.create', 'payroll.edit', 'payroll.comment', 'payroll.deductions.manage', 'payroll.salary-adjustments.manage', 'loans.*', 'reports.*', 'self-service.use', 'tasks.*', 'appraisals.*',
        ],
        'hr-manager' => [
            'hr.*', 'leave.*', 'roster.*', 'attendance.*', 'allowance.*', 'overtime.*', 'payroll.view',
            'payroll.create', 'payroll.edit', 'payroll.comment', 'payroll.deductions.manage', 'payroll.salary-adjustments.manage', 'loans.*', 'reports.*', 'admin.workflow.manage', 'self-service.use', 'tasks.*', 'appraisals.*',
        ],
        'director' => ['hr.employees.view', 'leave.view', 'roster.view', 'payroll.view', 'reports.view', 'reports.generate', 'reports.executive', 'self-service.use', 'tasks.use', 'appraisals.view', 'planning.view', 'accounting.view', 'budgeting.view', 'payments.view', 'receivables.view'],
        'director-general' => ['hr.employees.view', 'leave.view', 'roster.view', 'attendance.view', 'allowance.view', 'overtime.view', 'payroll.view', 'payroll.approve', 'loans.view', 'reports.view', 'reports.generate', 'reports.executive',
            'self-service.use', 'tasks.use', 'tasks.view-all', 'appraisals.view', 'planning.view', 'accounting.view', 'budgeting.view', 'payments.view', 'receivables.view', 'procurement.view', 'procurement.approve',
            'inventory.view', 'assets.view', 'fleet.view'],
        'director-finance' => ['accounting.view', 'budgeting.view', 'payments.view', 'receivables.view', 'procurement.view', 'inventory.view', 'assets.view', 'planning.view', 'payroll.view',
            'reports.view', 'reports.generate', 'reports.executive', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'director-hr-admin' => ['hr.employees.view', 'leave.view', 'roster.view', 'attendance.view', 'allowance.view', 'overtime.view', 'payroll.view', 'loans.view', 'appraisals.view', 'fleet.view', 'assets.view',
            'reports.view', 'reports.generate', 'reports.executive', 'self-service.use', 'tasks.use'],
        'director-planning' => ['planning.view', 'budgeting.view', 'tasks.view-all', 'appraisals.view', 'reports.view', 'reports.generate', 'reports.executive', 'self-service.use', 'tasks.use'],
        'hod' => [
            'hr.employees.view-department', 'leave.department.view', 'leave.approve-hod', 'leave.annual-roster.manage', 'leave.absence.manage', 'roster.department.view', 'roster.manage',
            'attendance.working-hours.department', 'allowance.department.view', 'allowance.daily.manage', 'overtime.view', 'reports.view', 'self-service.use',
            'tasks.use', 'tasks.assign', 'tasks.activities.manage', 'appraisals.use', 'appraisals.review', 'planning.department', 'budgeting.prepare', 'payments.request', 'procurement.request',
        ],
        'section-head' => [
            'hr.employees.view-section', 'roster.section.view', 'roster.manage', 'attendance.working-hours.section', 'allowance.daily.manage', 'reports.view', 'self-service.use',
            'tasks.use', 'tasks.assign', 'appraisals.use', 'appraisals.review',
        ],
        'accountant' => ['accounting.*', 'budgeting.*', 'payments.*', 'receivables.*', 'procurement.view', 'inventory.view', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'finance-manager' => ['accounting.*', 'budgeting.*', 'payments.*', 'receivables.*', 'procurement.view', 'inventory.view', 'assets.view', 'reports.*', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'cashier' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.petty-cash', 'receivables.view', 'receivables.receipts', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'billing-officer' => ['receivables.view', 'receivables.bill', 'receivables.receipts', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'budget-officer' => ['budgeting.*', 'planning.view', 'accounting.view', 'reports.view', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'planning-officer' => ['planning.*', 'budgeting.view', 'tasks.view-all', 'reports.view', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'procurement-officer' => ['procurement.view', 'procurement.request', 'procurement.suppliers', 'procurement.orders', 'procurement.receive', 'inventory.view', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'procurement-manager' => ['procurement.*', 'inventory.view', 'budgeting.view', 'reports.view', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'storekeeper' => ['inventory.view', 'inventory.request', 'inventory.catalogue', 'inventory.stores', 'procurement.view', 'procurement.receive', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'transport-officer' => ['fleet.*', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'asset-officer' => ['assets.*', 'self-service.use', 'tasks.use', 'appraisals.use'],
        'employee' => ['self-service.use', 'tasks.use', 'appraisals.use'],
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

    /** Creates missing system roles and gives them the default permissions they lack; never removes any. */
    public static function addMissingToCompanies(): int
    {
        $added = 0;
        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, function () use (&$added, $companyId) {
                setPermissionsTeamId($companyId);
                foreach (self::ROLES as $name => $patterns) {
                    $role = Role::where('name', $name)->where('guard_name', 'web')->where('company_id', $companyId)->first();
                    if (! $role) {
                        $role = Role::findOrCreate($name, 'web');
                        $role->forceFill(['is_system' => true])->save();
                    }
                    $missing = array_values(array_diff(self::expand($patterns), $role->permissions->pluck('name')->all()));
                    if ($missing) {
                        $role->givePermissionTo($missing);
                        $added += count($missing);
                    }
                }
            });
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $added;
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
