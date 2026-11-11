<?php

namespace Database\Seeders;

use App\Models\ErpModule;
use Illuminate\Database\Seeder;

class ErpModuleSeeder extends Seeder
{
    /** Modules opened from inside Human Resources instead of their own dashboard tile. */
    public const INSIDE_HR = ['roster', 'leave', 'attendance', 'allowance', 'overtime'];

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
                'route_name'  => 'hr.dashboard',
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
                'route_name'  => 'roster.dashboard',
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
                'route_name'  => 'leave.dashboard',
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
                'route_name'  => 'attendance.dashboard',
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
                'route_name'  => 'allowance.dashboard',
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
                'route_name'  => 'overtime.dashboard',
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
                'route_name'  => 'payroll.dashboard',
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
                'route_name'  => 'loans.dashboard',
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
                'route_name'  => 'accounting.dashboard',
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
                'route_name'  => 'bills.dashboard',
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
                'route_name'  => 'procurement.dashboard',
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
                'route_name'  => 'assets.dashboard',
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
                'route_name'  => 'appraisals.dashboard',
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
                'route_name'  => 'fleet.dashboard',
                'category'    => 'Fleet',
                'sort_order'  => 50,
                'is_core'     => false,
            ],

            // ── Reports ────────────────────────────────────────────────
            [
                'slug'        => 'reports',
                'name'        => 'Reports',
                'description' => 'HR reports — employee lists, leave summaries, contract expiry and attendance.',
                'icon'        => 'bi-bar-chart-line',
                'color'       => 'teal',
                'route_name'  => 'reports.dashboard',
                'category'    => 'HR',
                'sort_order'  => 18,
                'is_core'     => false,
            ],

            // ── Inventory ──────────────────────────────────────────────
            [
                'slug'        => 'inventory',
                'name'        => 'Inventory',
                'description' => 'Manage stock items, categories, stock-in/out movements and low-stock alerts.',
                'icon'        => 'bi-boxes',
                'color'       => 'success',
                'route_name'  => 'inventory.dashboard',
                'category'    => 'Inventory',
                'sort_order'  => 60,
                'is_core'     => false,
            ],
        ];

        foreach ($modules as $data) {
            $data['parent_slug'] = in_array($data['slug'], self::INSIDE_HR, true) ? 'hr' : null;
            ErpModule::updateOrCreate(['slug' => $data['slug']], $data);
        }

        $this->command?->info('ERP modules seeded: ' . count($modules));
    }
}
