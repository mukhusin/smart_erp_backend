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
        ],
        'audit' => [
            'audit.view' => 'Audit start page, audit checks and payroll totals (auditors)',
            'audit.queries' => 'Raise, send back and close audit queries (auditors)',
            'audit.engagements' => 'Give and end access for external audit firms',
        ],
        'self-service' => [
            'self-service.use' => 'Use employee self-service',
        ],
        'hr' => [
            'hr.employees.view' => 'View all employees',
            'hr.employees.view-branch' => 'View employees of own branch',
            'hr.employees.view-department' => 'View employees of own department and departments they head',
            'hr.employees.view-section' => 'View employees of own section',
            'hr.employees.create' => 'Register employees',
            'hr.employees.edit' => 'Edit employees',
            'hr.employees.delete' => 'Delete employees',
            'hr.contracts.manage' => 'Manage contracts',
            'hr.messages.send' => 'Send SMS, email and notices to staff',
            'hr.sick-sheets.manage' => 'Enter and view sick sheets (excuse of duty)',
            'hr.residents.manage' => 'Manage interns and residents',
            'hr.departments.manage' => 'Manage departments and sections',
            'hr.positions.manage' => 'Manage job positions',
            'hr.contract-types.manage' => 'Manage contract types',
            'hr.number-formats.manage' => 'Manage employee number formats',
            'hr.licences.manage' => 'Manage professional licences',
            'hr.banks.manage' => 'Manage banks',
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
            'leave.balances.manage' => 'Adjust leave balances',
            'leave.annual-roster.manage' => 'Manage annual leave roster',
            'leave.absence.manage' => 'Manage absence requests',
        ],
        'roster' => [
            'roster.view' => 'View rosters',
            'roster.department.view' => 'View department rosters',
            'roster.section.view' => 'View section roster details',
            'roster.manage' => 'Create and update duty rosters',
            'roster.timetables.manage' => 'Manage duty codes and working hours',
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
            'payroll.salary-adjustments.manage' => 'Manage salary adjustments',
            'payroll.deductions.manage' => 'Manage other deductions',
            'payroll.salary-adjustments.submit' => 'Submit salary adjustments to director',
            'payroll.salary-adjustments.finance-comment' => 'Comment on salary adjustments as finance',
            'payroll.salary-adjustments.approve' => 'Approve salary adjustments',
        ],
        'loans' => [
            'loans.view' => 'View loans',
            'loans.manage' => 'Manage employee loans',
        ],
        'tasks' => [
            'tasks.use' => 'Use tasks (own tasks, progress, comments)',
            'tasks.assign' => 'Assign tasks to staff in own scope and review them',
            'tasks.activities.manage' => 'Manage department activities',
            'tasks.view-all' => 'View all tasks of the company',
        ],
        'planning' => [
            'planning.department' => 'Prepare the annual plan of own departments',
            'planning.view' => 'View all plans',
            'planning.manage' => 'Manage strategic and annual plans',
        ],
        'budgeting' => [
            'budgeting.prepare' => 'Prepare the budget of own departments and request transfers',
            'budgeting.view' => 'View all budgets and budget vs actual',
            'budgeting.manage' => 'Run the budget cycle, ceilings and transfers for any department',
        ],
        'appraisals' => [
            'appraisals.use' => 'Own appraisal (self-assessment, acknowledgement)',
            'appraisals.review' => 'See and review appraisals of staff in own scope (mid-year review)',
            'appraisals.view' => 'View all appraisals',
            'appraisals.manage' => 'Manage appraisal periods, settings and competencies',
        ],
        'reports' => [
            'reports.view' => 'View reports',
            'reports.generate' => 'Generate and export reports',
        ],
        'accounting' => [
            'accounting.view' => 'View accounts, journals, ledger and financial reports',
            'accounting.journals' => 'Enter manual journals',
            'accounting.reconcile' => 'Reconcile bank accounts with bank statements',
            'accounting.opening' => 'Load opening balances at go-live (account balances, creditors, debtors, breakdowns)',
            'accounting.manage' => 'Manage chart of accounts, fiscal years, fund sources, bank accounts and settings',
        ],
        'payments' => [
            'payments.request' => 'Raise payment requests for own departments',
            'payments.view' => 'View all payment requests, vouchers and payment lists',
            'payments.vouchers' => 'Prepare and void payment vouchers, cancel approved requests',
            'payments.pay' => 'Prepare payment lists and record bank references (cashier)',
            'payments.petty-cash' => 'Keep a petty cash float: record petty cash vouchers and ask for top-ups',
            'payments.manage' => 'Manage payment settings and act on any payment',
        ],
        'receivables' => [
            'receivables.view' => 'View customers, invoices, receipts and ageing',
            'receivables.bill' => 'Manage customers, prepare and issue invoices, request credit notes',
            'receivables.receipts' => 'Record money received and request receipt voids (cashier)',
            'receivables.manage' => 'Billing settings, void invoices and act on any billing record',
        ],
        'bills' => [
            'bills.view' => 'View bills',
            'bills.manage' => 'Manage bills and payments',
        ],
        'procurement' => [
            'procurement.view' => 'View suppliers, plans, requisitions and orders',
            'procurement.request' => 'Raise purchase requisitions for own departments',
            'procurement.suppliers' => 'Maintain the supplier register (details, bank accounts, documents)',
            'procurement.orders' => 'Request quotations, propose awards and prepare purchase orders (procurement officer)',
            'procurement.receive' => 'Receive goods into any store',
            'procurement.manage' => 'Run procurement and blacklist suppliers (head of procurement)',
            'procurement.approve' => 'Approve purchase requests',
        ],
        'assets' => [
            'assets.view' => 'View the asset register, holders, values and reports',
            'assets.manage' => 'Register, assign, transfer, revalue and dispose of assets; run depreciation; handle fault and return reports',
        ],
        'fleet' => [
            'fleet.view' => 'View vehicles',
            'fleet.manage' => 'Manage vehicles, fuel and maintenance',
        ],
        'inventory' => [
            'inventory.view' => 'View the catalogue, stores and stock of all stores',
            'inventory.request' => 'Request store items for departments in own scope (staff always may for their own department)',
            'inventory.catalogue' => 'Maintain the item catalogue, categories and units',
            'inventory.stores' => 'Set up stores, locations and store staff',
            'inventory.manage' => 'Manage items, stores and stock movements',
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
