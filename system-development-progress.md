# Smart ERP — System Development Progress

**Project:** Smart ERP  
**Source Reference:** `/home/mukhusin-siraji/Dev/outside/hrms` (live HRMS — read-only reference, never modify)  
**ERP Path:** `/home/mukhusin-siraji/Dev/outside/smart-erp`  
**Stack:** Laravel 11, Bootstrap 5, Bootstrap Icons (`bi-*`), Spatie Laravel Permission, Blade templates  
**Last Updated:** 2026-04-23

---

## Project Goal

Migrate a live, production HRMS (`/hrms`) into a full Enterprise Resource Planning system (`/smart-erp`). The HRMS is ~95% HR-focused. The ERP keeps all HR functionality and expands it with Accounting, Bills, Procurement, Assets, Appraisals, Fleet, and Inventory modules — all under a clean modular folder structure with a modern, user-friendly UI.

**Core rules:**
- HRMS is live — reference only, never modified
- HR module was ~95% done before this session; other modules were stubs
- Multi-tenant: every record scoped to `company_id` via `CompanyContext` service
- Module access controlled by `middleware:module:X` — modules can be enabled/disabled per company

---

## Architecture & Key Patterns

### Multi-Tenancy
- Every controller injects `App\Services\CompanyContext` and filters all queries with `->where('company_id', $this->ctx->id())`
- `SetCompanyContext` middleware runs on every web request and resolves the active company from the authenticated user

### Module Access Control
- `erp_modules` table stores all module definitions (slug, name, icon, category, sort order)
- `company_modules` pivot table controls which modules a company has enabled
- `CheckModuleAccess` middleware validates module slug on protected route groups
- Sidebar shows locked state (`bi-lock`) for disabled modules

### Authentication — Single Device Login
- Login accepts **username or email** (auto-detects which field to try first)
- `isActive: true` guard on every auth attempt
- `SingleDeviceSessionManager` (Cache-based): registers session ID on login, destroys previous session on new login from another device
- `EnsureSessionTimeout` middleware: validates session on every request, logs out with flash warning if session is hijacked or timed out
- Login view has Caps Lock detection and single-device warning notice

### Double-Entry Accounting
- Journal entries validated for balance: `abs(totalDebit - totalCredit) < 0.01`
- Real-time JS balance checker in the journal create/edit view (green/red alert)
- `post()` action changes status from `draft` to `posted` (irreversible)

### Atomic DB Operations
- Bill payments: `DB::transaction()` updates `paid_amount` on bill and recalculates status
- Stock movements: `DB::transaction()` records movement and increments/decrements/sets `quantity_on_hand`
- Purchase orders: `DB::transaction()` creates PO items and marks linked PR as `ordered`

---

## Database — Migrations

### HR Migrations (2026-04-21)

| Migration | Table |
|---|---|
| `000000_create_users_table` | `users` (username, email, isActive, company_id) |
| `120847_create_permission_tables` | Spatie permission tables |
| `120920_create_companies_table` | `companies` |
| `120925_create_company_branches_table` | `company_branches` |
| `120930_add_company_id_to_users` | adds company_id FK to users |
| `122725_create_banks_table` | `banks` |
| `122725_create_departments_table` | `departments` |
| `122725_create_job_positions_table` | `job_positions` |
| `122725_create_nature_of_contracts_table` | `nature_of_contracts` |
| `122725_create_salary_scales_table` | `salary_scales` |
| `122726_create_employees_table` | `employees` |
| `122728_create_employee_bank_accounts_table` | `employee_bank_accounts` |
| `122728_create_employee_contracts_table` | `employee_contracts` |
| `122728_create_employee_dependants_table` | `employee_dependants` |
| `122728_create_employee_documents_table` | `employee_documents` |
| `122728_create_employee_experiences_table` | `employee_experiences` |
| `122728_create_employee_qualifications_table` | `employee_qualifications` |
| `122728_create_emp_supervisors_table` | `emp_supervisors` |
| `122728_create_head_of_departments_table` | `head_of_departments` |
| `135330_create_erp_modules_table` | `erp_modules` |
| `135333_create_company_modules_table` | `company_modules` |
| `200000–202009` | leave_types, public_holidays, roster_codes, allowance_categories, attendance_claim_reasons, loan_providers, payroll_date_settings, roster_timetables, rosters, department_allowances, emp_leaves, absence_requests, emp_allowances, employee_loans, employee_monthly_advances, overtimes, attendance_transas, annual_leave_rosters, payroll_sheets, payroll_sheet_details, salary_adjustments, leave_deductions, allowance_sheets, daily_allowances, overtime_sheets, loan_histories, attendance_claims |

### ERP Extension Migrations (2026-04-22)

| Migration | Table | Key Columns |
|---|---|---|
| `100000` | `accounts` | type (asset/liability/equity/revenue/expense), parent_id, code, is_active |
| `100001` | `journal_entries` | status (draft/posted/reversed), date, reference, description |
| `100002` | `journal_entry_lines` | account_id, debit, credit (decimal:2) |
| `100010` | `suppliers` | name, email, phone, address, is_active |
| `100011` | `bills` | supplier_id, bill_number, total_amount, paid_amount, status, due_date |
| `100012` | `bill_payments` | bill_id, amount, method, payment_date, reference |
| `100020` | `purchase_requests` | department_id, requested_by, pr_number, status, date |
| `100021` | `purchase_request_items` | item_name, quantity, unit, estimated_cost, notes |
| `100022` | `purchase_orders` | supplier_id, purchase_request_id, po_number, total_amount, status |
| `100023` | `purchase_order_items` | item_name, quantity, unit, unit_price, total_price |
| `100030` | `asset_categories` | name, description |
| `100031` | `asset_locations` | name, description |
| `100032` | `assets` | category_id, location_id, department_id, assigned_to (employee), serial_number, status, purchase_date, purchase_cost, warranty_expiry |
| `100040` | `appraisal_rating_scales` | name, min_percent, max_percent, description |
| `100041` | `department_appraisals` | department_id, title, period, is_active |
| `100042` | `appraisal_kpis` | department_appraisal_id, name, weight, description |
| `100050` | `vehicles` | plate_number, make, model, year, color, type, status, current_driver_id, insurance_expiry, inspection_expiry, purchase_date, purchase_cost |
| `100051` | `fuel_records` | vehicle_id, date, liters, cost_per_liter, total_cost, odometer_reading, filled_by |
| `100052` | `vehicle_maintenance_logs` | vehicle_id, date, type, description, service_provider, done_by, cost, next_service_date |
| `100060` | `inventory_categories` | name, description |
| `100061` | `inventory_items` | code, name, category_id, unit, unit_cost, quantity_on_hand, minimum_stock, is_active |
| `100062` | `stock_movements` | inventory_item_id, type (in/out/adjustment), quantity, date, reference, notes, created_by |

---

## Models (65 total)

### Core / Auth
`User`, `Company`, `CompanyBranch`, `CompanyModule`, `ErpModule`

### HR
`Employee`, `Department`, `JobPosition`, `SalaryScale`, `NatureOfContract`, `Bank`,
`EmployeeContract`, `EmployeeBankAccount`, `EmployeeDependant`, `EmployeeDocument`,
`EmployeeExperience`, `EmployeeQualification`, `EmpSupervisor`, `HeadOfDepartment`

### Roster / Leave / Attendance
`Roster`, `RosterTimetable`, `RosterCode`, `AnnualLeaveRoster`,
`EmpLeave`, `LeaveType`, `AbsenceRequest`, `PublicHoliday`,
`AttendanceTransa`, `AttendanceClaim`, `AttendanceClaimReason`

### Payroll / Allowance / Overtime / Loans
`PayrollSheet`, `PayrollSheetDetail`, `PayrollDateSetting`, `SalaryAdjustment`,
`EmpAllowance`, `DepartmentAllowance`, `AllowanceSheet`, `AllowanceCategory`, `DailyAllowance`,
`Overtime`, `OvertimeSheet`,
`EmployeeLoan`, `EmployeeMonthlyAdvance`, `LoanProvider`, `LoanHistory`

### Finance (ERP New)
`Account`, `JournalEntry`, `JournalEntryLine`, `Supplier`, `Bill`, `BillPayment`,
`PurchaseRequest`, `PurchaseRequestItem`, `PurchaseOrder`, `PurchaseOrderItem`

### Assets / Appraisals (ERP New)
`Asset`, `AssetCategory`, `AssetLocation`,
`AppraisalRatingScale`, `DepartmentAppraisal`, `AppraisalKpi`

### Fleet / Inventory (ERP New)
`Vehicle`, `FuelRecord`, `VehicleMaintenanceLog`,
`InventoryCategory`, `InventoryItem`, `StockMovement`

---

## Controllers (54 total)

### Auth
`Auth/AuthController` — username/email dual login, single-device session, isActive guard

### HR
`HR/EmployeeController`, `HR/DepartmentController`, `HR/JobPositionController`,
`HR/SalaryScaleController`, `HR/ContractController`, `HR/ContractTypeController`

### Roster
`Roster/DutyRosterController`, `Roster/TimetableController`,
`Roster/AnnualLeaveRosterController`, `Roster/RosterCodeController`

### Leave
`Leave/LeaveRequestController`, `Leave/LeaveTypeController`,
`Leave/AbsenceRequestController`, `Leave/PublicHolidayController`

### Attendance
`Attendance/AttendanceController`, `Attendance/AttendanceClaimController`

### Allowance / Overtime
`Allowance/EmpAllowanceController`, `Allowance/DailyAllowanceController`,
`Allowance/AllowanceSheetController`, `Allowance/AllowanceCategoryController`,
`Overtime/OvertimeController`, `Overtime/OvertimeSheetController`

### Payroll / Loans
`Payroll/PayrollSheetController`, `Payroll/SalaryAdjustmentController`, `Payroll/DeductionController`,
`Loan/LoanController`, `Loan/AdvanceController`, `Loan/LoanProviderController`

### Admin
`Admin/UserController`, `Admin/RoleController`, `Admin/PermissionController`,
`Admin/CompanyController`, `Admin/SettingController`

### Self-Service / HOD
`SelfService/SelfServiceController` — dashboard, profile, my-leaves, request-leave, duty-roster, payslip
`Hod/HodController` — dept leaves (HOD approve/reject), annual leaves, employees, roster

### Reports
`Reports/ReportController` — employees, leaves, contracts, attendance (all with filters + print)

### Accounting (ERP New)
`Accounting/ChartOfAccountController` — Full CRUD, prevents deletion if journal lines exist
`Accounting/JournalController` — Full CRUD + `post()`, balance validation, DB transaction
`Accounting/TransactionController` — Index, filter by date range and account

### Bills (ERP New)
`Bills/SupplierController` — Full CRUD, prevents deletion if bills exist
`Bills/BillController` — Full CRUD, status auto-calculation
`Bills/PaymentController` — CRUD, atomic `paid_amount` increment/decrement via DB transaction, `destroy()` reverses payment

### Procurement (ERP New)
`Procurement/PurchaseRequestController` — Full CRUD + `approve()` + `reject()`, dynamic line items
`Procurement/PurchaseOrderController` — Full CRUD, auto-totals, links PO to PR (marks PR as `ordered`)

### Assets (ERP New)
`Assets/AssetCategoryController`, `Assets/AssetLocationController`, `Assets/AssetController` — Full CRUD

### Appraisals (ERP New)
`Appraisals/AppraisalRatingScaleController` — Full CRUD
`Appraisals/DepartmentAppraisalController` — Full CRUD + `storeKpi()` + `destroyKpi()` for inline KPI management

### Fleet (ERP New)
`Fleet/VehicleController` — Full CRUD
`Fleet/FuelRecordController` — Index, create, store, show, destroy (no edit — records are immutable)
`Fleet/MaintenanceController` — Index, create, store, show, destroy

### Inventory (ERP New)
`Inventory/InventoryCategoryController` — Full CRUD
`Inventory/InventoryItemController` — Full CRUD + show with movement history
`Inventory/StockMovementController` — Index, create, store, show (atomic quantity_on_hand updates)

---

## Views

### Layouts
- `layouts/app.blade.php` — Full sidebar (all 17 modules with locked state), topbar with company badge, notifications dropdown, user profile dropdown
- `layouts/auth.blade.php` — Minimal auth page wrapper

### Auth
- `auth/login.blade.php` — Username/email field, Caps Lock warning, single-device notice, session warning flash

### HR
- `hr/employees/` — index (searchable), create, edit, show (with sub-tabs: qualifications, experience, dependants, contracts, bank accounts, documents)
- `hr/departments/`, `hr/positions/`, `hr/salary-scales/`, `hr/contract-types/` — index, create, edit

### Roster / Leave / Attendance / Payroll / etc.
All sub-module views created (index, create, edit, show as applicable) for:
- Roster: duty roster, timetables, annual leave roster, roster codes
- Leave: requests, types, absence requests, public holidays
- Attendance: transactions, claims
- Allowance: employee allowances, daily allowances, sheets, categories
- Overtime: overtime records, sheets
- Payroll: sheets, salary adjustments, deductions
- Loans: employee loans, advances, providers

### Admin
- `admin/users/`, `admin/roles/`, `admin/permissions/`, `admin/companies/` — full CRUD views

### Accounting (ERP New)
- `accounting/chart/` — index (grouped by type), create, edit, show
- `accounting/journals/` — index, create (real-time balance checker JS + dynamic line items), edit, show
- `accounting/transactions/index` — filtered by date range and account

### Bills (ERP New)
- `bills/suppliers/` — index, create, edit, show (with bill history)
- `bills/` — index (status badges), create (JS total calc), edit, show (inline payment history)
- `bills/payments/` — index, create (auto-fills from bill balance), show (with reverse payment action)

### Procurement (ERP New)
- `procurement/requests/` — index (approve/reject buttons), create (dynamic items JS), edit, show
- `procurement/orders/` — index, create (dynamic items with running total, pre-fills from PR), edit, show

### Assets (ERP New)
- `assets/` — index, create, edit, show
- `assets/categories/` — index, create, edit
- `assets/locations/` — index, create, edit

### Appraisals (ERP New)
- `appraisals/scales/` — index, create, edit
- `appraisals/templates/` — index, create, edit, show (with inline KPI add form)

### Fleet (ERP New)
- `fleet/vehicles/` — index (card grid with status badges), create, edit, show (fuel records + maintenance history)
- `fleet/fuel/` — index, create (auto-calc total cost JS), show
- `fleet/maintenance/` — index, create, show (overdue/due-soon badge on next service date)

### Inventory (ERP New)
- `inventory/categories/` — index, create, edit
- `inventory/items/` — index (low-stock badge, filter by category), create, edit, show (movement history + stock value)
- `inventory/movements/` — index (filter by item + type), create (live current-stock info panel JS), show

---

## Services & Middleware

### Services

| File | Purpose |
|---|---|
| `app/Services/CompanyContext.php` | Resolves active company_id for multi-tenant scoping |
| `app/Services/ModuleAccessService.php` | Checks if a module is enabled for the current company |
| `app/Services/SingleDeviceSessionManager.php` | Cache-based single-device session enforcement (migrated from HRMS) |

### Middleware

| File | Purpose | Registered |
|---|---|---|
| `SetCompanyContext` | Resolves and binds company context on every request | Global web |
| `EnsureSessionTimeout` | Validates single-device session + inactivity timeout | Global web |
| `CheckModuleAccess` | Validates module access for protected route groups | Alias: `module` |

---

## Module Seeder

`database/seeders/ErpModuleSeeder.php` — Seeds all 17 modules and enables them all for the first company.

| Slug | Name | Category |
|---|---|---|
| self-service | Self Service | Core |
| admin | Administration | Core |
| hr | Human Resources | HR |
| roster | Roster & Schedule | HR |
| leave | Leave Management | HR |
| attendance | Attendance | HR |
| allowance | Allowances | HR |
| overtime | Overtime | HR |
| payroll | Payroll | HR |
| loans | Loans & Advances | HR |
| accounting | Accounting | Finance |
| bills | Bills Management | Finance |
| procurement | Procurement | Finance |
| assets | Asset Management | Assets |
| appraisals | Appraisals | Performance |
| fleet | Fleet Management | Fleet |
| inventory | Inventory | Inventory |

**To activate all modules for the first company:**
```bash
php artisan db:seed --class=ErpModuleSeeder
```

---

## Routes Structure (`routes/web.php`)

```
GET  /login                  AuthController@showLogin
POST /login                  AuthController@login
POST /logout                 AuthController@logout

Authenticated group (middleware: auth):
  /                          Dashboard
  /profile                   Profile (stub)

  HR group (middleware: module:hr):
    hr/employees             EmployeeController (resource)
    hr/departments           DepartmentController (resource)
    hr/positions             JobPositionController (resource)
    hr/salary-scales         SalaryScaleController (resource)
    hr/contract-types        ContractTypeController (resource)

  Roster  (middleware: module:roster)   — duty, timetables, annual-leave, codes
  Leave   (middleware: module:leave)    — requests, types, absence, holidays
  Attend  (middleware: module:attendance) — transactions, claims
  Allow   (middleware: module:allowance) — emp, daily, sheets, categories
  OT      (middleware: module:overtime)  — records, sheets
  Payroll (middleware: module:payroll)   — sheets, adjustments, deductions
  Loans   (middleware: module:loans)     — loans, advances, providers

  Accounting group (middleware: module:accounting):
    accounting/chart         ChartOfAccountController (resource)
    accounting/journals      JournalController (resource + POST /{journal}/post)
    accounting/transactions  TransactionController (index only)

  Bills group (middleware: module:bills):
    bills/                   BillController (resource)
    bills/payments           PaymentController (resource)
    bills/suppliers          SupplierController (resource)

  Procurement group (middleware: module:procurement):
    procurement/requests     PurchaseRequestController (resource + approve + reject)
    procurement/orders       PurchaseOrderController (resource)

  Assets group (middleware: module:assets):
    assets/                  AssetController (resource)
    assets/categories        AssetCategoryController (resource)
    assets/locations         AssetLocationController (resource)

  Appraisals group (middleware: module:appraisals):
    appraisals/scales        AppraisalRatingScaleController (resource)
    appraisals/templates     DepartmentAppraisalController (resource + storeKpi + destroyKpi)

  Fleet group (middleware: module:fleet):
    fleet/vehicles           VehicleController (resource)
    fleet/fuel               FuelRecordController (index, create, store, show, destroy)
    fleet/maintenance        MaintenanceController (index, create, store, show, destroy)

  Inventory group (middleware: module:inventory):
    inventory/categories     InventoryCategoryController (resource)
    inventory/items          InventoryItemController (resource)
    inventory/movements      StockMovementController (index, create, store, show)

  Admin group:
    admin/users, admin/roles, admin/permissions, admin/companies
```

---

## What Was Built (2026-04-23 Session)

### Phase 1 — Roles & Permissions
- `RolePermissionSeeder` seeds 4 roles (super-admin, hr-officer, hod, employee) and 14 permissions
- `User::employee()` hasOne relationship added
- `DatabaseSeeder` calls `RolePermissionSeeder` before `ErpModuleSeeder`

### Phase 2 — Employee Self-Service
- `SelfServiceController` with dashboard, profile, my-leaves, request-leave, duty-roster, payslip
- Views: `self-service/dashboard`, `profile`, `my-leaves`, `leave-request`, `duty-roster`, `payslip`, `no-profile`
- Sidebar: Self-Service section visible to all authenticated users

### Phase 3 — HR Approval Flows
- `LeaveRequestController::approve()` + `reject()` — HR-level approval
- `AnnualLeaveRosterController::approve()` + `reject()` — HR annual leave approval
- `HodController` with dept leaves, annual leaves, employees, roster views
- Leave index/show rewritten with HOD approval status and approval trail
- Sidebar: HOD Management section role-gated with `@hasanyrole`

### Phase 4 — Scheduled Commands
- `contract:status` — activates pending contracts, expires ended ones
- `leave:status` — activates approved leaves that started, marks ended leaves as reported (EmpLeave + AnnualLeaveRoster)
- `leave:annual-balance` — recalculates actual balance for active annual leave entries
- All 3 registered in `routes/console.php` via `Schedule::command()`

### Phase 5 — Dashboard KPI Widgets
- `DashboardController` queries 6 live KPIs: active employees, pending leaves, on leave, unpaid bills, pending PRs, low stock
- Dashboard view: "My Portal" button + 6-card KPI bar with colored top borders linking to each module

### Phase 6 — Reports Module
- `ReportController` with 4 report endpoints (employees, leaves, contracts, attendance)
- Views: `reports/index` (hub), `employees`, `leaves`, `contracts`, `attendance`
- All reports print-ready with a Print button (no PDF library required)
- `reports` module added to `ErpModuleSeeder` (category: HR, sort: 18)
- Route group `/reports/*` with `module:reports` middleware
- Sidebar: Reports section with sub-links for all 4 report types

## What Remains (Not Yet Built)

| Feature | Notes |
|---|---|
| **Appraisal Scoring** | `DepartmentAppraisal` is templates only — actual employee appraisal instances (scoring per employee, results, history) not built |
| **Messaging / Notifications** | MessagingController in HRMS. Not yet migrated |
| **Password Reset** | Route stub (`password.request`) exists but no controller/views created |
| **File Uploads** | Employee documents model exists; file upload/storage not wired in views |
| **Payroll Computation Engine** | Sheet model exists; actual salary calculation logic not yet implemented |

---

## Key Technical Decisions & Gotchas

1. **Username + Email login** — `users` table has both `username` and `email`. Auth tries the matching field first based on whether input is email-shaped, then falls back to the other. `isActive: true` guard on every attempt.

2. **Single-device session** — Implemented via Laravel Cache (`user_session_{id}` key). On new login the old session file/DB row is deleted. Enforced on every request via `EnsureSessionTimeout` middleware.

3. **Bills resource naming** — `Route::resource('bills', ...)` with explicit `.names([...])` mapping was used (not `Route::resource('/', ...)`) because Laravel cannot generate valid URLs for a resource mounted at `/`.

4. **PurchaseRequest variable conflict** — `PurchaseRequestController` uses `Request $req` for the HTTP request (not `$request`) to avoid conflict with the `PurchaseRequest $request` model binding parameter name.

5. **Inventory quantity atomicity** — Stock movements use `increment()` / `decrement()` for in/out (SQL-level atomic) and `update(['quantity_on_hand' => $qty])` for adjustments, all inside `DB::transaction()`.

6. **Journal balance enforcement** — Both server-side (`abs(debit - credit) < 0.01`) and client-side (real-time JS checker in the journal form showing green/red alert).

7. **Write tool requires prior Read** — When upgrading existing stub controllers, the file must be Read first before Write can overwrite it.
