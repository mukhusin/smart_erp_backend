<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
    .sidebar-module-back{padding:.5rem 1rem .25rem;border-bottom:1px solid rgba(255,255,255,.1);margin-bottom:.25rem}
    .sidebar-back-link{display:flex;align-items:center;font-size:.8rem;color:rgba(255,255,255,.7);text-decoration:none;padding:.35rem .5rem;border-radius:6px;transition:background .15s,color .15s}
    .sidebar-back-link:hover{background:rgba(255,255,255,.1);color:#fff}
    </style>
    @stack('styles')
</head>
<body>

<div id="wrapper">

    {{-- ── SIDEBAR ──────────────────────────────────── --}}
    <nav id="sidebar">

        {{-- Brand --}}
        <a href="{{ route('dashboard') }}" class="sidebar-brand">
            <div class="sidebar-brand-icon">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </div>
            <span class="sidebar-brand-text">{{ config('app.name') }}</span>
        </a>

        @php
            $modMap = ($erpModules ?? collect())->keyBy('slug');
            $canHr          = $modMap->get('hr')?->has_access          ?? false;
            $canRoster      = $modMap->get('roster')?->has_access       ?? false;
            $canLeave       = $modMap->get('leave')?->has_access        ?? false;
            $canAttendance  = $modMap->get('attendance')?->has_access   ?? false;
            $canAllowance   = $modMap->get('allowance')?->has_access    ?? false;
            $canOvertime    = $modMap->get('overtime')?->has_access     ?? false;
            $canPayroll     = $modMap->get('payroll')?->has_access      ?? false;
            $canLoans       = $modMap->get('loans')?->has_access        ?? false;
            $canAccounting  = $modMap->get('accounting')?->has_access   ?? false;
            $canBills       = $modMap->get('bills')?->has_access        ?? false;
            $canProcurement = $modMap->get('procurement')?->has_access  ?? false;
            $canAssets      = $modMap->get('assets')?->has_access       ?? false;
            $canAppraisals  = $modMap->get('appraisals')?->has_access   ?? false;
            $canFleet       = $modMap->get('fleet')?->has_access        ?? false;
            $canInventory   = $modMap->get('inventory')?->has_access    ?? false;
            $canReports     = $modMap->get('reports')?->has_access      ?? false;

            $sidebarModule = match(true) {
                request()->routeIs('hr.*')           => 'hr',
                request()->routeIs('roster.*')       => 'roster',
                request()->routeIs('leave.*')        => 'leave',
                request()->routeIs('attendance.*')   => 'attendance',
                request()->routeIs('allowance.*')    => 'allowance',
                request()->routeIs('overtime.*')     => 'overtime',
                request()->routeIs('payroll.*')      => 'payroll',
                request()->routeIs('loans.*')        => 'loans',
                request()->routeIs('accounting.*')   => 'accounting',
                request()->routeIs('bills.*')        => 'bills',
                request()->routeIs('procurement.*')  => 'procurement',
                request()->routeIs('assets.*')       => 'assets',
                request()->routeIs('appraisals.*')   => 'appraisals',
                request()->routeIs('fleet.*')        => 'fleet',
                request()->routeIs('inventory.*')    => 'inventory',
                request()->routeIs('reports.*')      => 'reports',
                request()->routeIs('self-service.*') => 'self-service',
                request()->routeIs('hod.*')          => 'hod',
                request()->routeIs('admin.*') || request()->routeIs('settings.*') => 'admin',
                default => null,
            };
        @endphp

        @if($sidebarModule)
        <div class="sidebar-module-back">
            <a href="{{ route('dashboard') }}" class="sidebar-back-link">
                <i class="bi bi-arrow-left-circle me-1"></i> All Modules
            </a>
        </div>
        @endif

        <ul class="sidebar-nav">

        {{-- ════ GLOBAL DASHBOARD (no active module) ════ --}}
        @if(!$sidebarModule)

            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    <span class="nav-label">All Modules</span>
                </a>
            </li>

            <li><span class="sidebar-heading">Personal</span></li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" data-bs-target="#subSelfServiceNav"
                   aria-expanded="{{ request()->is('self-service*') ? 'true' : 'false' }}">
                    <i class="bi bi-person-circle"></i>
                    <span class="nav-label">My Portal</span>
                    <i class="bi bi-chevron-right nav-arrow"></i>
                </a>
                <ul class="collapse sidebar-submenu {{ request()->is('self-service*') ? 'show' : '' }}" id="subSelfServiceNav">
                    <li><a href="{{ route('self-service.dashboard') }}" class="nav-link {{ request()->routeIs('self-service.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-house"></i><span class="nav-label">My Dashboard</span></a></li>
                    <li><a href="{{ route('self-service.profile') }}" class="nav-link {{ request()->routeIs('self-service.profile') ? 'active' : '' }}">
                        <i class="bi bi-person-badge"></i><span class="nav-label">My Profile</span></a></li>
                    <li><a href="{{ route('self-service.my-leaves') }}" class="nav-link {{ request()->routeIs('self-service.my-leaves') ? 'active' : '' }}">
                        <i class="bi bi-calendar-x"></i><span class="nav-label">My Leaves</span></a></li>
                    <li><a href="{{ route('self-service.duty-roster') }}" class="nav-link {{ request()->routeIs('self-service.duty-roster') ? 'active' : '' }}">
                        <i class="bi bi-calendar3-week"></i><span class="nav-label">My Duty Roster</span></a></li>
                    <li><a href="{{ route('self-service.payslip') }}" class="nav-link {{ request()->routeIs('self-service.payslip') ? 'active' : '' }}">
                        <i class="bi bi-receipt"></i><span class="nav-label">My Payslips</span></a></li>
                </ul>
            </li>

            @hasanyrole('hod|hr-officer|super-admin')
            <li><span class="sidebar-heading">Department</span></li>
            <li class="nav-item">
                <a href="{{ route('hod.dashboard') }}" class="nav-link {{ request()->routeIs('hod.*') ? 'active' : '' }}">
                    <i class="bi bi-building-gear"></i>
                    <span class="nav-label">HOD Management</span>
                </a>
            </li>
            @endhasanyrole

            <li><span class="sidebar-heading">System</span></li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" data-bs-target="#subAdminNav"
                   aria-expanded="{{ request()->is('admin*') ? 'true' : 'false' }}">
                    <i class="bi bi-shield-lock"></i>
                    <span class="nav-label">Administration</span>
                    <i class="bi bi-chevron-right nav-arrow"></i>
                </a>
                <ul class="collapse sidebar-submenu {{ request()->is('admin*') ? 'show' : '' }}" id="subAdminNav">
                    <li><a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <i class="bi bi-person-fill-gear"></i><span class="nav-label">Users</span></a></li>
                    <li><a href="{{ route('admin.roles.index') }}" class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                        <i class="bi bi-person-fill-lock"></i><span class="nav-label">Roles</span></a></li>
                    <li><a href="{{ route('admin.companies.index') }}" class="nav-link {{ request()->routeIs('admin.companies.*') ? 'active' : '' }}">
                        <i class="bi bi-buildings"></i><span class="nav-label">Companies</span></a></li>
                </ul>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">
                    <i class="bi bi-gear"></i>
                    <span class="nav-label">Settings</span>
                </a>
            </li>

        {{-- ════ SELF-SERVICE MODULE ════ --}}
        @elseif($sidebarModule === 'self-service')
            <li><span class="sidebar-heading">My Portal</span></li>
            <li class="nav-item"><a href="{{ route('self-service.dashboard') }}" class="nav-link {{ request()->routeIs('self-service.dashboard') ? 'active' : '' }}">
                <i class="bi bi-house"></i><span class="nav-label">My Dashboard</span></a></li>
            <li class="nav-item"><a href="{{ route('self-service.profile') }}" class="nav-link {{ request()->routeIs('self-service.profile') ? 'active' : '' }}">
                <i class="bi bi-person-badge"></i><span class="nav-label">My Profile</span></a></li>
            <li class="nav-item"><a href="{{ route('self-service.my-leaves') }}" class="nav-link {{ request()->routeIs('self-service.my-leaves') ? 'active' : '' }}">
                <i class="bi bi-calendar-x"></i><span class="nav-label">My Leaves</span></a></li>
            <li class="nav-item"><a href="{{ route('self-service.request-leave') }}" class="nav-link {{ request()->routeIs('self-service.request-leave') ? 'active' : '' }}">
                <i class="bi bi-calendar-plus"></i><span class="nav-label">Request Leave</span></a></li>
            <li class="nav-item"><a href="{{ route('self-service.duty-roster') }}" class="nav-link {{ request()->routeIs('self-service.duty-roster') ? 'active' : '' }}">
                <i class="bi bi-calendar3-week"></i><span class="nav-label">My Duty Roster</span></a></li>
            <li class="nav-item"><a href="{{ route('self-service.payslip') }}" class="nav-link {{ request()->routeIs('self-service.payslip') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i><span class="nav-label">My Payslips</span></a></li>

        {{-- ════ HOD MODULE ════ --}}
        @elseif($sidebarModule === 'hod')
            <li><span class="sidebar-heading">HOD Management</span></li>
            <li class="nav-item"><a href="{{ route('hod.dashboard') }}" class="nav-link {{ request()->routeIs('hod.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Dashboard</span></a></li>
            <li class="nav-item"><a href="{{ route('hod.employees') }}" class="nav-link {{ request()->routeIs('hod.employees') ? 'active' : '' }}">
                <i class="bi bi-people"></i><span class="nav-label">Dept Employees</span></a></li>
            <li class="nav-item"><a href="{{ route('hod.leaves') }}" class="nav-link {{ request()->routeIs('hod.leaves*') ? 'active' : '' }}">
                <i class="bi bi-calendar-x"></i><span class="nav-label">Leave Requests</span></a></li>
            <li class="nav-item"><a href="{{ route('hod.annual-leaves') }}" class="nav-link {{ request()->routeIs('hod.annual-leaves*') ? 'active' : '' }}">
                <i class="bi bi-calendar2-heart"></i><span class="nav-label">Annual Leaves</span></a></li>
            <li class="nav-item"><a href="{{ route('hod.roster') }}" class="nav-link {{ request()->routeIs('hod.roster') ? 'active' : '' }}">
                <i class="bi bi-calendar3-week"></i><span class="nav-label">Dept Roster</span></a></li>

        {{-- ════ HR MODULE ════ --}}
        @elseif($sidebarModule === 'hr')
            <li><span class="sidebar-heading">HR Management</span></li>
            <li class="nav-item"><a href="{{ route('hr.dashboard') }}" class="nav-link {{ request()->routeIs('hr.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('hr.employees.index') }}" class="nav-link {{ request()->routeIs('hr.employees.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i><span class="nav-label">Employees</span></a></li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" data-bs-target="#subDeptNav"
                   aria-expanded="{{ request()->routeIs('hr.departments.*') || request()->routeIs('hr.positions.*') || request()->routeIs('hr.salary-scales.*') ? 'true' : 'false' }}">
                    <i class="bi bi-diagram-3"></i><span class="nav-label">Organisation</span>
                    <i class="bi bi-chevron-right nav-arrow"></i>
                </a>
                <ul class="collapse sidebar-submenu {{ request()->routeIs('hr.departments.*') || request()->routeIs('hr.positions.*') || request()->routeIs('hr.salary-scales.*') ? 'show' : '' }}" id="subDeptNav">
                    <li><a href="{{ route('hr.departments.index') }}" class="nav-link {{ request()->routeIs('hr.departments.*') ? 'active' : '' }}">
                        <i class="bi bi-building"></i><span class="nav-label">Departments</span></a></li>
                    <li><a href="{{ route('hr.positions.index') }}" class="nav-link {{ request()->routeIs('hr.positions.*') ? 'active' : '' }}">
                        <i class="bi bi-person-badge"></i><span class="nav-label">Job Positions</span></a></li>
                    <li><a href="{{ route('hr.salary-scales.index') }}" class="nav-link {{ request()->routeIs('hr.salary-scales.*') ? 'active' : '' }}">
                        <i class="bi bi-bar-chart-steps"></i><span class="nav-label">Salary Scales</span></a></li>
                </ul>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" data-bs-target="#subContractNav"
                   aria-expanded="{{ request()->routeIs('hr.contract-types.*') ? 'true' : 'false' }}">
                    <i class="bi bi-file-earmark-text"></i><span class="nav-label">Contracts</span>
                    <i class="bi bi-chevron-right nav-arrow"></i>
                </a>
                <ul class="collapse sidebar-submenu {{ request()->routeIs('hr.contract-types.*') ? 'show' : '' }}" id="subContractNav">
                    <li><a href="{{ route('hr.contract-types.index') }}" class="nav-link {{ request()->routeIs('hr.contract-types.*') ? 'active' : '' }}">
                        <i class="bi bi-ui-checks"></i><span class="nav-label">Contract Types</span></a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="{{ route('messaging.index') }}" class="nav-link {{ request()->routeIs('messaging.*') ? 'active' : '' }}">
                <i class="bi bi-chat-dots"></i><span class="nav-label">Messaging</span></a></li>

        {{-- ════ ROSTER MODULE ════ --}}
        @elseif($sidebarModule === 'roster')
            <li><span class="sidebar-heading">Roster & Schedule</span></li>
            <li class="nav-item"><a href="{{ route('roster.dashboard') }}" class="nav-link {{ request()->routeIs('roster.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('roster.duty.index') }}" class="nav-link {{ request()->routeIs('roster.duty.*') ? 'active' : '' }}">
                <i class="bi bi-calendar-week"></i><span class="nav-label">Duty Roster</span></a></li>
            <li class="nav-item"><a href="{{ route('roster.timetables.index') }}" class="nav-link {{ request()->routeIs('roster.timetables.*') ? 'active' : '' }}">
                <i class="bi bi-clock-history"></i><span class="nav-label">Timetables</span></a></li>
            <li class="nav-item"><a href="{{ route('roster.annual-leave.index') }}" class="nav-link {{ request()->routeIs('roster.annual-leave.*') ? 'active' : '' }}">
                <i class="bi bi-calendar2-heart"></i><span class="nav-label">Annual Leave Roster</span></a></li>
            <li class="nav-item"><a href="{{ route('roster.codes.index') }}" class="nav-link {{ request()->routeIs('roster.codes.*') ? 'active' : '' }}">
                <i class="bi bi-tag"></i><span class="nav-label">Roster Codes</span></a></li>

        {{-- ════ LEAVE MODULE ════ --}}
        @elseif($sidebarModule === 'leave')
            <li><span class="sidebar-heading">Leave Management</span></li>
            <li class="nav-item"><a href="{{ route('leave.dashboard') }}" class="nav-link {{ request()->routeIs('leave.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('leave.requests.index') }}" class="nav-link {{ request()->routeIs('leave.requests.*') ? 'active' : '' }}">
                <i class="bi bi-calendar-minus"></i><span class="nav-label">Leave Requests</span></a></li>
            <li class="nav-item"><a href="{{ route('leave.types.index') }}" class="nav-link {{ request()->routeIs('leave.types.*') ? 'active' : '' }}">
                <i class="bi bi-list-check"></i><span class="nav-label">Leave Types</span></a></li>
            <li class="nav-item"><a href="{{ route('leave.absence.index') }}" class="nav-link {{ request()->routeIs('leave.absence.*') ? 'active' : '' }}">
                <i class="bi bi-person-dash"></i><span class="nav-label">Absence Requests</span></a></li>
            <li class="nav-item"><a href="{{ route('leave.public-holidays.index') }}" class="nav-link {{ request()->routeIs('leave.public-holidays.*') ? 'active' : '' }}">
                <i class="bi bi-star"></i><span class="nav-label">Public Holidays</span></a></li>

        {{-- ════ ATTENDANCE MODULE ════ --}}
        @elseif($sidebarModule === 'attendance')
            <li><span class="sidebar-heading">Attendance</span></li>
            <li class="nav-item"><a href="{{ route('attendance.dashboard') }}" class="nav-link {{ request()->routeIs('attendance.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('attendance.index') }}" class="nav-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}">
                <i class="bi bi-table"></i><span class="nav-label">Transactions</span></a></li>
            <li class="nav-item"><a href="{{ route('attendance.claims.index') }}" class="nav-link {{ request()->routeIs('attendance.claims.*') ? 'active' : '' }}">
                <i class="bi bi-patch-question"></i><span class="nav-label">Claims</span></a></li>

        {{-- ════ ALLOWANCE MODULE ════ --}}
        @elseif($sidebarModule === 'allowance')
            <li><span class="sidebar-heading">Allowances</span></li>
            <li class="nav-item"><a href="{{ route('allowance.dashboard') }}" class="nav-link {{ request()->routeIs('allowance.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('allowance.requests.index') }}" class="nav-link {{ request()->routeIs('allowance.requests.*') ? 'active' : '' }}">
                <i class="bi bi-cash-coin"></i><span class="nav-label">Emp Allowances</span></a></li>
            <li class="nav-item"><a href="{{ route('allowance.daily.index') }}" class="nav-link {{ request()->routeIs('allowance.daily.*') ? 'active' : '' }}">
                <i class="bi bi-calendar-day"></i><span class="nav-label">Daily Allowances</span></a></li>
            <li class="nav-item"><a href="{{ route('allowance.sheets.index') }}" class="nav-link {{ request()->routeIs('allowance.sheets.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i><span class="nav-label">Allowance Sheets</span></a></li>
            <li class="nav-item"><a href="{{ route('allowance.categories.index') }}" class="nav-link {{ request()->routeIs('allowance.categories.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i><span class="nav-label">Categories</span></a></li>

        {{-- ════ OVERTIME MODULE ════ --}}
        @elseif($sidebarModule === 'overtime')
            <li><span class="sidebar-heading">Overtime</span></li>
            <li class="nav-item"><a href="{{ route('overtime.dashboard') }}" class="nav-link {{ request()->routeIs('overtime.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('overtime.index') }}" class="nav-link {{ request()->routeIs('overtime.index') ? 'active' : '' }}">
                <i class="bi bi-clock-fill"></i><span class="nav-label">Overtime Records</span></a></li>
            <li class="nav-item"><a href="{{ route('overtime.sheets.index') }}" class="nav-link {{ request()->routeIs('overtime.sheets.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-spreadsheet"></i><span class="nav-label">Overtime Sheets</span></a></li>

        {{-- ════ PAYROLL MODULE ════ --}}
        @elseif($sidebarModule === 'payroll')
            <li><span class="sidebar-heading">Payroll</span></li>
            <li class="nav-item"><a href="{{ route('payroll.dashboard') }}" class="nav-link {{ request()->routeIs('payroll.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('payroll.sheets.index') }}" class="nav-link {{ request()->routeIs('payroll.sheets.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-bar-graph"></i><span class="nav-label">Payroll Sheets</span></a></li>
            <li class="nav-item"><a href="{{ route('payroll.salary-adjustments.index') }}" class="nav-link {{ request()->routeIs('payroll.salary-adjustments.*') ? 'active' : '' }}">
                <i class="bi bi-pencil-square"></i><span class="nav-label">Salary Adjustments</span></a></li>
            <li class="nav-item"><a href="{{ route('payroll.deductions.index') }}" class="nav-link {{ request()->routeIs('payroll.deductions.*') ? 'active' : '' }}">
                <i class="bi bi-dash-circle"></i><span class="nav-label">Deductions Config</span></a></li>

        {{-- ════ LOANS MODULE ════ --}}
        @elseif($sidebarModule === 'loans')
            <li><span class="sidebar-heading">Loans & Advances</span></li>
            <li class="nav-item"><a href="{{ route('loans.dashboard') }}" class="nav-link {{ request()->routeIs('loans.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('loans.index') }}" class="nav-link {{ request()->routeIs('loans.index') ? 'active' : '' }}">
                <i class="bi bi-credit-card"></i><span class="nav-label">Employee Loans</span></a></li>
            <li class="nav-item"><a href="{{ route('loans.advances.index') }}" class="nav-link {{ request()->routeIs('loans.advances.*') ? 'active' : '' }}">
                <i class="bi bi-wallet2"></i><span class="nav-label">Monthly Advances</span></a></li>
            <li class="nav-item"><a href="{{ route('loans.providers.index') }}" class="nav-link {{ request()->routeIs('loans.providers.*') ? 'active' : '' }}">
                <i class="bi bi-building-check"></i><span class="nav-label">Loan Providers</span></a></li>

        {{-- ════ ACCOUNTING MODULE ════ --}}
        @elseif($sidebarModule === 'accounting')
            <li><span class="sidebar-heading">Accounting</span></li>
            <li class="nav-item"><a href="{{ route('accounting.dashboard') }}" class="nav-link {{ request()->routeIs('accounting.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('accounting.chart.index') }}" class="nav-link {{ request()->routeIs('accounting.chart.*') ? 'active' : '' }}">
                <i class="bi bi-list-nested"></i><span class="nav-label">Chart of Accounts</span></a></li>
            <li class="nav-item"><a href="{{ route('accounting.journals.index') }}" class="nav-link {{ request()->routeIs('accounting.journals.*') ? 'active' : '' }}">
                <i class="bi bi-journals"></i><span class="nav-label">Journal Entries</span></a></li>
            <li class="nav-item"><a href="{{ route('accounting.transactions.index') }}" class="nav-link {{ request()->routeIs('accounting.transactions.*') ? 'active' : '' }}">
                <i class="bi bi-arrow-left-right"></i><span class="nav-label">Transactions</span></a></li>

        {{-- ════ BILLS MODULE ════ --}}
        @elseif($sidebarModule === 'bills')
            <li><span class="sidebar-heading">Bills Management</span></li>
            <li class="nav-item"><a href="{{ route('bills.dashboard') }}" class="nav-link {{ request()->routeIs('bills.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('bills.index') }}" class="nav-link {{ request()->routeIs('bills.index') ? 'active' : '' }}">
                <i class="bi bi-file-text"></i><span class="nav-label">Bills</span></a></li>
            <li class="nav-item"><a href="{{ route('bills.payments.index') }}" class="nav-link {{ request()->routeIs('bills.payments.*') ? 'active' : '' }}">
                <i class="bi bi-credit-card-2-front"></i><span class="nav-label">Payments</span></a></li>
            <li class="nav-item"><a href="{{ route('bills.suppliers.index') }}" class="nav-link {{ request()->routeIs('bills.suppliers.*') ? 'active' : '' }}">
                <i class="bi bi-truck"></i><span class="nav-label">Suppliers</span></a></li>

        {{-- ════ PROCUREMENT MODULE ════ --}}
        @elseif($sidebarModule === 'procurement')
            <li><span class="sidebar-heading">Procurement</span></li>
            <li class="nav-item"><a href="{{ route('procurement.dashboard') }}" class="nav-link {{ request()->routeIs('procurement.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('procurement.requests.index') }}" class="nav-link {{ request()->routeIs('procurement.requests.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-plus"></i><span class="nav-label">Purchase Requests</span></a></li>
            <li class="nav-item"><a href="{{ route('procurement.orders.index') }}" class="nav-link {{ request()->routeIs('procurement.orders.*') ? 'active' : '' }}">
                <i class="bi bi-cart-check"></i><span class="nav-label">Purchase Orders</span></a></li>

        {{-- ════ ASSETS MODULE ════ --}}
        @elseif($sidebarModule === 'assets')
            <li><span class="sidebar-heading">Asset Management</span></li>
            <li class="nav-item"><a href="{{ route('assets.dashboard') }}" class="nav-link {{ request()->routeIs('assets.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('assets.index') }}" class="nav-link {{ request()->routeIs('assets.index') || request()->routeIs('assets.show') || request()->routeIs('assets.create') || request()->routeIs('assets.edit') ? 'active' : '' }}">
                <i class="bi bi-laptop"></i><span class="nav-label">Assets</span></a></li>
            <li class="nav-item"><a href="{{ route('assets.categories.index') }}" class="nav-link {{ request()->routeIs('assets.categories.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i><span class="nav-label">Categories</span></a></li>
            <li class="nav-item"><a href="{{ route('assets.locations.index') }}" class="nav-link {{ request()->routeIs('assets.locations.*') ? 'active' : '' }}">
                <i class="bi bi-geo-alt"></i><span class="nav-label">Locations</span></a></li>

        {{-- ════ APPRAISALS MODULE ════ --}}
        @elseif($sidebarModule === 'appraisals')
            <li><span class="sidebar-heading">Appraisals</span></li>
            <li class="nav-item"><a href="{{ route('appraisals.dashboard') }}" class="nav-link {{ request()->routeIs('appraisals.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('appraisals.templates.index') }}" class="nav-link {{ request()->routeIs('appraisals.templates.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-check"></i><span class="nav-label">Appraisal Templates</span></a></li>
            <li class="nav-item"><a href="{{ route('appraisals.scales.index') }}" class="nav-link {{ request()->routeIs('appraisals.scales.*') ? 'active' : '' }}">
                <i class="bi bi-sliders2"></i><span class="nav-label">Rating Scales</span></a></li>
            <li class="nav-item"><a href="{{ route('appraisals.employee.index') }}" class="nav-link {{ request()->routeIs('appraisals.employee.*') ? 'active' : '' }}">
                <i class="bi bi-person-check"></i><span class="nav-label">Employee Appraisals</span></a></li>

        {{-- ════ FLEET MODULE ════ --}}
        @elseif($sidebarModule === 'fleet')
            <li><span class="sidebar-heading">Fleet Management</span></li>
            <li class="nav-item"><a href="{{ route('fleet.dashboard') }}" class="nav-link {{ request()->routeIs('fleet.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('fleet.vehicles.index') }}" class="nav-link {{ request()->routeIs('fleet.vehicles.*') ? 'active' : '' }}">
                <i class="bi bi-car-front"></i><span class="nav-label">Vehicles</span></a></li>
            <li class="nav-item"><a href="{{ route('fleet.fuel.index') }}" class="nav-link {{ request()->routeIs('fleet.fuel.*') ? 'active' : '' }}">
                <i class="bi bi-fuel-pump"></i><span class="nav-label">Fuel Records</span></a></li>
            <li class="nav-item"><a href="{{ route('fleet.maintenance.index') }}" class="nav-link {{ request()->routeIs('fleet.maintenance.*') ? 'active' : '' }}">
                <i class="bi bi-tools"></i><span class="nav-label">Maintenance</span></a></li>

        {{-- ════ INVENTORY MODULE ════ --}}
        @elseif($sidebarModule === 'inventory')
            <li><span class="sidebar-heading">Inventory</span></li>
            <li class="nav-item"><a href="{{ route('inventory.dashboard') }}" class="nav-link {{ request()->routeIs('inventory.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span></a></li>
            <li class="nav-item"><a href="{{ route('inventory.items.index') }}" class="nav-link {{ request()->routeIs('inventory.items.*') ? 'active' : '' }}">
                <i class="bi bi-box-seam"></i><span class="nav-label">Items</span></a></li>
            <li class="nav-item"><a href="{{ route('inventory.movements.index') }}" class="nav-link {{ request()->routeIs('inventory.movements.*') ? 'active' : '' }}">
                <i class="bi bi-arrow-left-right"></i><span class="nav-label">Stock Movements</span></a></li>
            <li class="nav-item"><a href="{{ route('inventory.categories.index') }}" class="nav-link {{ request()->routeIs('inventory.categories.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i><span class="nav-label">Categories</span></a></li>

        {{-- ════ REPORTS MODULE ════ --}}
        @elseif($sidebarModule === 'reports')
            <li><span class="sidebar-heading">Reports</span></li>
            <li class="nav-item"><a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.index') ? 'active' : '' }}">
                <i class="bi bi-grid-1x2"></i><span class="nav-label">Report Hub</span></a></li>
            <li class="nav-item"><a href="{{ route('reports.employees') }}" class="nav-link {{ request()->routeIs('reports.employees') ? 'active' : '' }}">
                <i class="bi bi-people"></i><span class="nav-label">Employee Report</span></a></li>
            <li class="nav-item"><a href="{{ route('reports.leaves') }}" class="nav-link {{ request()->routeIs('reports.leaves') ? 'active' : '' }}">
                <i class="bi bi-calendar-x"></i><span class="nav-label">Leave Report</span></a></li>
            <li class="nav-item"><a href="{{ route('reports.contracts') }}" class="nav-link {{ request()->routeIs('reports.contracts') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text"></i><span class="nav-label">Contract Report</span></a></li>
            <li class="nav-item"><a href="{{ route('reports.attendance') }}" class="nav-link {{ request()->routeIs('reports.attendance') ? 'active' : '' }}">
                <i class="bi bi-fingerprint"></i><span class="nav-label">Attendance Summary</span></a></li>

        {{-- ════ ADMIN MODULE ════ --}}
        @elseif($sidebarModule === 'admin')
            <li><span class="sidebar-heading">Administration</span></li>
            <li class="nav-item"><a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <i class="bi bi-person-fill-gear"></i><span class="nav-label">Users</span></a></li>
            <li class="nav-item"><a href="{{ route('admin.roles.index') }}" class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                <i class="bi bi-person-fill-lock"></i><span class="nav-label">Roles</span></a></li>
            <li class="nav-item"><a href="{{ route('admin.permissions.index') }}" class="nav-link {{ request()->routeIs('admin.permissions.*') ? 'active' : '' }}">
                <i class="bi bi-key"></i><span class="nav-label">Permissions</span></a></li>
            <li class="nav-item"><a href="{{ route('admin.companies.index') }}" class="nav-link {{ request()->routeIs('admin.companies.*') ? 'active' : '' }}">
                <i class="bi bi-buildings"></i><span class="nav-label">Companies</span></a></li>
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">
                <i class="bi bi-gear"></i><span class="nav-label">Settings</span></a></li>

        @endif

        </ul>
    </nav>
    {{-- End Sidebar --}}

    {{-- Mobile overlay --}}
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    {{-- ── MAIN CONTENT ──────────────────────────────────── --}}
    <div id="main-content">

        {{-- Topbar --}}
        <header id="topbar">
            <button class="toggle-btn" type="button">
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-breadcrumb">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        @yield('breadcrumb')
                    </ol>
                </nav>
            </div>

            <div class="topbar-actions">
                @if(isset($currentCompany))
                    <span class="topbar-company-badge d-none d-md-inline">
                        <i class="bi bi-buildings me-1"></i>{{ $currentCompany->short_name ?? $currentCompany->name }}
                    </span>
                @endif

                {{-- Notifications --}}
                <div class="dropdown">
                    <button class="btn btn-sm btn-light position-relative" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem">
                            0
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:300px">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        <li><div class="dropdown-item-text text-muted small py-3 text-center">No new notifications</div></li>
                    </ul>
                </div>

                {{-- Profile --}}
                <div class="dropdown">
                    <button class="btn btn-sm d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <img src="{{ auth()->user()->avatar_url }}" class="rounded-circle" width="32" height="32" style="object-fit:cover">
                        <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        {{-- End Topbar --}}

        {{-- Page Content --}}
        <main class="page-content">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @yield('content')
        </main>

    </div>
    {{-- End Main Content --}}

</div>

@stack('scripts')
</body>
</html>
