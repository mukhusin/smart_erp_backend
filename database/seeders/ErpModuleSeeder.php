<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ErpModule;
use Illuminate\Database\Seeder;

class ErpModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // ── Core (always accessible) ───────────────────────────────
            [
                'slug'        => 'self-service',
                'name'        => 'Self Service',
                'description' => 'Employee portal — view payslips, apply for leave, check attendance and more.',
                'icon'        => 'bi-person-circle',
                'color'       => 'primary',
                'route_name'  => 'dashboard',
                'category'    => 'Core',
                'sort_order'  => 1,
                'is_core'     => true,
            ],
            [
                'slug'        => 'admin',
                'name'        => 'Administration',
                'description' => 'User management, roles, permissions and company settings.',
                'icon'        => 'bi-shield-lock',
                'color'       => 'dark',
                'route_name'  => 'admin.users.index',
                'category'    => 'Core',
                'sort_order'  => 2,
                'is_core'     => true,
            ],

            // ── HR ─────────────────────────────────────────────────────
            [
                'slug'        => 'hr',
                'name'        => 'Human Resources',
                'description' => 'Employee records, contracts, departments, positions and organisational structure.',
                'icon'        => 'bi-people-fill',
                'color'       => 'info',
                'route_name'  => 'hr.employees.index',
                'category'    => 'HR',
                'sort_order'  => 10,
                'is_core'     => false,
            ],
            [
                'slug'        => 'roster',
                'name'        => 'Roster & Schedule',
                'description' => 'Duty rosters, timetables, annual leave scheduling and shift management.',
                'icon'        => 'bi-calendar3-week',
                'color'       => 'teal',
                'route_name'  => 'roster.duty.index',
                'category'    => 'HR',
                'sort_order'  => 11,
                'is_core'     => false,
            ],
            [
                'slug'        => 'leave',
                'name'        => 'Leave Management',
                'description' => 'Annual, sick, maternity and other leave requests with multi-level approval.',
                'icon'        => 'bi-calendar-x',
                'color'       => 'warning',
                'route_name'  => 'leave.requests.index',
                'category'    => 'HR',
                'sort_order'  => 12,
                'is_core'     => false,
            ],
            [
                'slug'        => 'attendance',
                'name'        => 'Attendance',
                'description' => 'Track daily attendance, punch-in/out records, claims and biometric integration.',
                'icon'        => 'bi-fingerprint',
                'color'       => 'secondary',
                'route_name'  => 'attendance.index',
                'category'    => 'HR',
                'sort_order'  => 13,
                'is_core'     => false,
            ],
            [
                'slug'        => 'allowance',
                'name'        => 'Allowances',
                'description' => 'Employee allowances, daily per-diems, department allowance rules and sheets.',
                'icon'        => 'bi-gift',
                'color'       => 'success',
                'route_name'  => 'allowance.requests.index',
                'category'    => 'HR',
                'sort_order'  => 14,
                'is_core'     => false,
            ],
            [
                'slug'        => 'overtime',
                'name'        => 'Overtime',
                'description' => 'Record and approve employee overtime hours and generate overtime sheets.',
                'icon'        => 'bi-hourglass-split',
                'color'       => 'orange',
                'route_name'  => 'overtime.index',
                'category'    => 'HR',
                'sort_order'  => 15,
                'is_core'     => false,
            ],
            [
                'slug'        => 'payroll',
                'name'        => 'Payroll',
                'description' => 'Monthly payroll processing, salary sheets, deductions and adjustments.',
                'icon'        => 'bi-cash-stack',
                'color'       => 'success',
                'route_name'  => 'payroll.sheets.index',
                'category'    => 'HR',
                'sort_order'  => 16,
                'is_core'     => false,
            ],
            [
                'slug'        => 'loans',
                'name'        => 'Loans & Advances',
                'description' => 'Employee loans, monthly advances and deduction tracking.',
                'icon'        => 'bi-bank',
                'color'       => 'danger',
                'route_name'  => 'loans.index',
                'category'    => 'HR',
                'sort_order'  => 17,
                'is_core'     => false,
            ],

            // ── Finance ────────────────────────────────────────────────
            [
                'slug'        => 'accounting',
                'name'        => 'Accounting',
                'description' => 'Chart of accounts, journal entries, transactions and financial statements.',
                'icon'        => 'bi-journal-bookmark',
                'color'       => 'primary',
                'route_name'  => 'accounting.chart.index',
                'category'    => 'Finance',
                'sort_order'  => 20,
                'is_core'     => false,
            ],
            [
                'slug'        => 'bills',
                'name'        => 'Bills Management',
                'description' => 'Manage supplier bills, payments and vendor accounts payable.',
                'icon'        => 'bi-receipt-cutoff',
                'color'       => 'warning',
                'route_name'  => 'bills.index',
                'category'    => 'Finance',
                'sort_order'  => 21,
                'is_core'     => false,
            ],
            [
                'slug'        => 'procurement',
                'name'        => 'Procurement',
                'description' => 'Purchase requests, purchase orders and vendor management.',
                'icon'        => 'bi-box-seam',
                'color'       => 'info',
                'route_name'  => 'procurement.requests.index',
                'category'    => 'Finance',
                'sort_order'  => 22,
                'is_core'     => false,
            ],

            // ── Assets ─────────────────────────────────────────────────
            [
                'slug'        => 'assets',
                'name'        => 'Asset Management',
                'description' => 'Track company assets, categories, locations, assignments and disposals.',
                'icon'        => 'bi-laptop',
                'color'       => 'secondary',
                'route_name'  => 'assets.index',
                'category'    => 'Assets',
                'sort_order'  => 30,
                'is_core'     => false,
            ],

            // ── Performance ────────────────────────────────────────────
            [
                'slug'        => 'appraisals',
                'name'        => 'Appraisals',
                'description' => 'Department appraisal templates, KPIs and employee performance evaluations.',
                'icon'        => 'bi-star-half',
                'color'       => 'warning',
                'route_name'  => 'appraisals.templates.index',
                'category'    => 'Performance',
                'sort_order'  => 40,
                'is_core'     => false,
            ],

            // ── Fleet ──────────────────────────────────────────────────
            [
                'slug'        => 'fleet',
                'name'        => 'Fleet Management',
                'description' => 'Vehicle registry, fuel consumption tracking, maintenance logs and driver assignments.',
                'icon'        => 'bi-truck-front',
                'color'       => 'primary',
                'route_name'  => 'fleet.vehicles.index',
                'category'    => 'Fleet',
                'sort_order'  => 50,
                'is_core'     => false,
            ],

            // ── Inventory ──────────────────────────────────────────────
            [
                'slug'        => 'inventory',
                'name'        => 'Inventory',
                'description' => 'Manage stock items, categories, stock-in/out movements and low-stock alerts.',
                'icon'        => 'bi-boxes',
                'color'       => 'success',
                'route_name'  => 'inventory.items.index',
                'category'    => 'Inventory',
                'sort_order'  => 60,
                'is_core'     => false,
            ],
        ];

        foreach ($modules as $data) {
            ErpModule::updateOrCreate(['slug' => $data['slug']], $data);
        }

        // Enable all modules for the first company (the default seeded company)
        $company = Company::first();
        if ($company) {
            $slugs = ErpModule::where('is_core', false)->pluck('slug');
            foreach ($slugs as $slug) {
                CompanyModule::updateOrCreate(
                    ['company_id' => $company->id, 'module_slug' => $slug],
                    ['is_enabled' => true, 'enabled_at' => now()]
                );
            }
            $this->command->info("Enabled all modules for company: {$company->name}");
        }

        $this->command->info('ERP modules seeded: ' . count($modules));
    }
}
