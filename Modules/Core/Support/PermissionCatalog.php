<?php

namespace Modules\Core\Support;

/**
 * Every permission in the ERP, grouped by module (erp_modules.slug).
 *
 * - Names are "<module>.<action>" so the module (category) is visible in the name.
 * - The frontend only checks these names (from GET /me); it never invents its own.
 * - A permission works only while its module is available to the user
 *   (enabled for the company, not locked for the user's branch or the user).
 * - Synced to the database by `php artisan erp:sync-permissions` (also run by the seeder and on company registration).
 * - HRMS permissions map to these names in hrms-migration-mapping.md ("Permissions").
 */
final class PermissionCatalog
{
    /** @var array<string, array<string, string>> module slug => [permission name => label] */
    public const MODULES = [
        'admin' => [
            'admin.users.view' => 'View users',
            'admin.users.manage' => 'Create, edit and deactivate users',
            'admin.roles.manage' => 'Manage roles and their permissions',
            'admin.roles.assign' => 'Assign and remove user roles and permissions',
            'admin.handovers.manage' => 'Hand over roles between users',
            'admin.module-locks.manage' => 'Lock modules for branches or users',
            'admin.branches.manage' => 'Manage branches',
            'admin.settings.manage' => 'Manage company settings',
            'admin.workflow.manage' => 'Manage approval workflows',
            'admin.audit.view' => 'View audit log',
            'admin.messaging.send' => 'Send SMS and email messages',
        ],
        'self-service' => [
            'self-service.use' => 'Use employee self-service',
        ],
        'hr' => [
            'hr.employees.view' => 'View employees',
            'hr.employees.create' => 'Register employees',
            'hr.employees.edit' => 'Edit employees',
            'hr.employees.delete' => 'Delete employees',
            'hr.contracts.manage' => 'Manage contracts',
            'hr.residents.manage' => 'Manage interns and residents',
            'hr.departments.manage' => 'Manage departments and sections',
            'hr.positions.manage' => 'Manage job positions',
            'hr.contract-types.manage' => 'Manage contract types',
            'hr.licences.manage' => 'Manage professional licences',
            'hr.admitted.manage' => 'Record admitted employees',
            'hr.excuse-duty.manage' => 'Record excuse of duty',
            'hr.abilities.manage' => 'Manage abilities',
            'hr.department-settings.manage' => 'Manage department settings',
        ],
        'leave' => [
            'leave.view' => 'View leave',
            'leave.department.view' => 'View department leave',
            'leave.approve-hod' => 'Approve leave as head of department',
            'leave.approve-hr' => 'Approve leave as HR',
            'leave.reject' => 'Reject leave',
            'leave.delete' => 'Delete leave of others',
            'leave.types.manage' => 'Manage leave types',
            'leave.annual-roster.manage' => 'Manage annual leave roster',
            'leave.absence.manage' => 'Manage absence requests',
        ],
        'roster' => [
            'roster.view' => 'View rosters',
            'roster.department.view' => 'View department rosters',
            'roster.section.view' => 'View section roster details',
            'roster.manage' => 'Create and update duty rosters',
            'roster.timetables.manage' => 'Manage duty timetables',
            'roster.holidays.manage' => 'Manage public holidays',
        ],
        'attendance' => [
            'attendance.view' => 'View attendance',
            'attendance.edit' => 'Edit attendance',
            'attendance.approve' => 'Approve attendance',
            'attendance.claims.approve' => 'Approve attendance claims',
            'attendance.working-hours.company' => 'View working hours of the whole company',
            'attendance.working-hours.department' => 'View working hours of own department',
            'attendance.working-hours.section' => 'View working hours of own section',
            'attendance.devices.manage' => 'Manage attendance devices',
        ],
        'allowance' => [
            'allowance.view' => 'View allowances',
            'allowance.department.view' => 'View department allowances',
            'allowance.manage' => 'Add and remove allowances',
            'allowance.assign' => 'Assign allowances to employees',
            'allowance.categories.manage' => 'Manage allowance categories',
            'allowance.daily.manage' => 'Record daily allowances',
            'allowance.department-ranges.manage' => 'Manage department allowance ranges',
        ],
        'overtime' => [
            'overtime.view' => 'View overtime',
            'overtime.manage' => 'Manage overtime',
            'overtime.department-ranges.manage' => 'Manage department overtime ranges',
        ],
        'payroll' => [
            'payroll.view' => 'View payroll',
            'payroll.create' => 'Generate payroll sheets',
            'payroll.edit' => 'Edit payroll',
            'payroll.upload' => 'Upload payroll',
            'payroll.comment' => 'Comment on payroll',
            'payroll.approve' => 'Approve payroll',
            'payroll.submit-to-payroll-officer' => 'Submit payroll to payroll officer',
            'payroll.submit-to-director' => 'Submit payroll to director',
            'payroll.submit-to-finance' => 'Submit payroll to finance',
            'payroll.reliefs.manage' => 'Manage reliefs',
            'payroll.statutory.manage' => 'Manage statutory memberships (HESLB, NSSF, NHIF...)',
            'payroll.banks.manage' => 'Manage banks',
            'payroll.salary-adjustments.manage' => 'Manage salary adjustments',
            'payroll.salary-adjustments.submit' => 'Submit salary adjustments to director',
            'payroll.salary-adjustments.finance-comment' => 'Comment on salary adjustments as finance',
            'payroll.salary-adjustments.approve' => 'Approve salary adjustments',
        ],
        'loans' => [
            'loans.view' => 'View loans',
            'loans.manage' => 'Manage employee loans',
        ],
        'appraisals' => [
            'appraisals.view' => 'View appraisals',
            'appraisals.manage' => 'Manage appraisal templates and scores',
        ],
        'reports' => [
            'reports.view' => 'View reports',
            'reports.generate' => 'Generate and export reports',
        ],
        'accounting' => [
            'accounting.view' => 'View accounts and journals',
            'accounting.manage' => 'Post journals and manage accounts',
        ],
        'bills' => [
            'bills.view' => 'View bills',
            'bills.manage' => 'Manage bills and payments',
        ],
        'procurement' => [
            'procurement.view' => 'View purchase requests and orders',
            'procurement.manage' => 'Manage purchase requests and orders',
            'procurement.approve' => 'Approve purchase requests',
        ],
        'assets' => [
            'assets.view' => 'View assets',
            'assets.create' => 'Add assets',
            'assets.edit' => 'Edit assets',
            'assets.delete' => 'Delete assets',
        ],
        'fleet' => [
            'fleet.view' => 'View vehicles',
            'fleet.manage' => 'Manage vehicles, fuel and maintenance',
        ],
        'inventory' => [
            'inventory.view' => 'View stock',
            'inventory.manage' => 'Manage items and stock movements',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::MODULES)));
    }

    public static function moduleOf(string $permission): ?string
    {
        foreach (self::MODULES as $module => $permissions) {
            if (isset($permissions[$permission])) {
                return $module;
            }
        }

        return null;
    }
}
