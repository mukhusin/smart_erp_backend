# HRMS → Smart ERP Data Migration Mapping

Generated 2026-11-09 from the **HRMS local database schema** (information_schema) and the **smart-erp migration files**
(the ERP local database `erp_db` has not been migrated, so its schema was read from `database/migrations`).
Row counts are from the local HRMS copy — production will differ. Regenerate after schema changes on either side.

Companion to `hrms-gap-list.md`: where a column has no ERP target, the gap item that adds it is named.

## 1. Principles

1. **Keep HRMS primary keys.** Insert every migrated row with its HRMS `id`. Foreign keys then stay valid
   without lookups, the two systems can be compared row by row during the parallel run, and **BioTime keeps
   working** (it uses `emp_code` = `employees.id`). The ERP database is fresh, so there are no collisions;
   reset each table's AUTO_INCREMENT after loading.
2. **Read-only source.** Migrate from a copy of the production HRMS database, never the live one. HRMS is never written to.
3. **Repeatable.** One command truncates the ERP HR tables and reloads everything, in dependency order. Run it nightly
   during the parallel payroll run; the final run is the cut-over.
4. **Company scope.** Create the Kairuki company and its branches first. Every row gets that `company_id`;
   HRMS `company_branche_id` → ERP `company_branch_id`.
5. **Generic renames** (applied automatically): camelCase → snake_case (`isActive` → `is_active`,
   `hasOvertime` → `has_overtime`, `withPay` → `with_pay`), payroll columns to lower case (`NSSF` → `nssf`,
   `PAYE` → `paye`), and typo fixes: `company_branche_id`, `employeement_type`, `out_standing_loan`,
   `attendancy_transa_id`, `directorAproved`, `employee_mothly_advances`, `employee_experiances`, `head_of_deaprtments`.
6. **Employee numbers unchanged.** `employees.st_no` → `employee_no` exactly; ERP number generation must not touch migrated staff.
7. **Balances copied, not recalculated:** leave balances, loan `returned`/`remain`, SACCOS totals. The ERP rules for these aren't built yet.
8. **Verify every run:** row count per table, and checksums — total `net_salary` per payroll sheet, sum of loan `remain`,
   sum of `actual_balance` per leave year, count of active contracts. A run that doesn't match fails.

## 2. Special cases

| Area | Plan |
|---|---|
| **Users & passwords** | Copy `users` with password hashes unchanged (bcrypt is portable) — staff keep their passwords. Username stays the email. |
| **Roles & permissions** | HRMS custom RBAC (`roles`, `permissions`, `permission_categories`, `users_roles`, `users_permissions`, `roles_permissions`, 136 permissions) → Spatie (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`). Needs a **slug mapping table** HRMS permission → ERP permission name, reviewed by HR/admin. |
| **Files** | Copy `storage/app/public/employee/{passport_size,document,dependat,licences}` to ERP storage. Build `employee_documents.file_path`; keep `profile_image` file names. |
| **Workflow** | ERP has no engine yet (#11). Finish in-flight approvals in HRMS before cut-over; migrate `workflow_*` history only once the engine exists (or keep it read-only in HRMS). |
| **Large tables** | `attendance_transas` (~534k), `employee_timetables` (~276k), `daily_allowances` (~276k), `allowance_sheets` (~122k): insert in chunks of 5–10k with foreign key checks off during load, on after (then verify). Option: attendance and timetables — last 3 months + any open payroll period only; older history stays in an archived read-only HRMS. |
| **Payroll history** | Migrate **all** payroll sheets and details (~9k rows) and their lines — staff look up old payslips. |
| **Open items** | Unpaid approved overtime/allowances and active loans migrate as open; paid ones are history linked to payslips. |
| **Not migrated** | Laravel system tables; `allowance_sheets_backup_20260208`; world data (`cities`, `countries`, `states`, `currencies`, `languages`, `timezones`) — reseed from the package in the ERP; `carousel_slides`; `authentication_log` (archive). |

## 3. Tables with an ERP target

Status: ✅ copy with generic renames only · 🟡 needs column decisions · ⚠️ needs ERP schema changes first

### Level 0 — reference data

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `banks` | 38 | ✅ | `isActive`→`is_active` | — | — |
| `leave_types` | 11 | ✅ | `withPay`→`with_pay`, `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `allowance_categories` | 16 | 🟡 | `isActive`→`is_active` | `from_afyabox`: → activity source config (integrations), not a column | `company_id`: Kairuki company id |
| `loan_providers` | 2 | ✅ | `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `nature_of_contracts` | 7 | ✅ | `employeement_type`→`employment_type`, `hasPayroll`→`has_payroll`, `hasAnnualLeave`→`has_annual_leave`, `isActive`→`is_active`, `hasAllowance`→`has_allowance`, `hasOvertime`→`has_overtime`, `hasEmergencyFund`→`has_emergency_fund` | — | `company_id`: Kairuki company id |
| `salary_scales` | 5 | 🟡 | — | — | `company_id`: Kairuki company id<br>`job_position_id`: null |
| `public_holidays` | 18 | ✅ | `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `roster_codes` | 23 | ✅ | — | — | `company_id`: Kairuki company id |
| `attendance_claim_reasons` | 3 | 🟡 | `isActive`→`is_active` | `employee_id`: drop (who created it) | `company_id`: Kairuki company id |
| `company_branches` | 3 | ⚠️ | — | `description`: add or drop (3 rows) | `company_id`: Kairuki company id<br>`code`: null — fill in ERP company settings<br>`phone`: null — fill in ERP company settings<br>`email`: null — fill in ERP company settings<br>`address`: null — fill in ERP company settings<br>`city`: null — fill in ERP company settings<br>`is_headquarters`: null — fill in ERP company settings<br>`is_active`: null — fill in ERP company settings |

### Level 1 — organisation & users

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `departments` | 47 | 🟡 | `company_branche_id`→`company_branch_id` | `parent`: → `parent_id`<br>`sub_parent`: **check**: third level. ERP has only `parent_id`; use `sub_parent ?? parent` after confirming meaning | `company_id`: Kairuki company id<br>`parent_id`: from `parent` / `sub_parent`<br>`is_active`: `true` (HRMS has no flag) |
| `job_positions` | 149 | 🟡 | — | — | `company_id`: Kairuki company id<br>`is_active`: `true` (HRMS has no flag) |
| `users` | 780 | 🟡 | — | — | `company_id`: Kairuki company id<br>`company_branch_id`: from HRMS `company_branche_id`<br>`is_active`: **ERP has both `isActive` and `is_active`** — copy `isActive`, then drop one column in ERP<br>`avatar`: null |

### Level 2 — employees

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `employees` | 776 | ⚠️ | `company_branche_id`→`company_branch_id`, `st_no`→`employee_no` | `patient_id`: → employee external IDs (E-14)<br>`created_by`: add to ERP (audit) | `company_id`: Kairuki company id<br>`probation_start`: **check production**: defined in the HRMS employees migration but missing in the local DB — copy if present, else null<br>`probation_period`: **check production**: defined in the HRMS employees migration but missing in the local DB — copy if present, else null<br>`probation_end`: **check production**: defined in the HRMS employees migration but missing in the local DB — copy if present, else null |

### Level 3 — employee details & setup

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `employee_contracts` | 1,555 | ⚠️ | `isActive`→`is_active` | `st_no`: → `contract_no` (HRMS shows st_no as "Contract ID")<br>`created_by`: add to ERP (audit)<br>`updated_by`: add to ERP (audit)<br>`company_branche_id`: drop | `contract_no`: from `st_no` |
| `employee_bank_accounts` | 705 | 🟡 | `isActive`→`is_active` | `name`: → `account_name`<br>`account`: → `account_number` | `account_name`: from `name`<br>`account_number`: from `account` |
| `employee_dependants` | 1 | ✅ | `isNextOfKin`→`is_next_of_kin` | — | — |
| `employee_documents` | 10 | 🟡 | — | — | `file_path`: `employee/document/{file_name}` after copying files |
| `employee_qualifications` | 36 | 🟡 | — | — | `institution`: null (HRMS has none) |
| `employee_funds` | 346 | ✅ | `isActive`→`is_active` | — | — |
| `nhifs` | 352 | ✅ | `isActive`→`is_active` | — | — |
| `nssfs` | 399 | ✅ | `isActive`→`is_active` | — | — |
| `heslbs` | 32 | ✅ | `isActive`→`is_active` | — | — |
| `nics` | 0 | ✅ | `isActive`→`is_active` | — | — |
| `tughes` | 0 | ✅ | `isActive`→`is_active` | — | — |
| `emp_supervisors` | 782 | ✅ | — | — | — |
| `department_allowances` | 149 | 🟡 | `isActive`→`is_active` | `created_by`: → `last_updated_by` if empty, else drop<br>`company_branche_id`: drop (company_id covers it) | `company_id`: Kairuki company id |
| `department_appraisals` | 3 | ✅ | `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `appraisal_kpis` | 4 | 🟡 | — | `updated_by`: drop | `weight`: null / default |
| `appraisal_rating_scales` | 5 | ✅ | — | — | `company_id`: Kairuki company id |
| `rosters` | 1,446 | ✅ | `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `roster_timetables` | 27 | 🟡 | `isActive`→`is_active` | `attendacy`: → `use_attendance` | `company_id`: Kairuki company id<br>`work_hour`: hours between check-in start and check-out end<br>`use_attendance`: from `attendacy` |
| `reliefs` | 50 | ⚠️ | — | `updated_by`: drop<br>`start_date`: add to ERP (relief validity)<br>`end_date`: add to ERP | `description`: null |
| `saccos_members` | 107 | ⚠️ | `isActive`→`is_active` | `total_amount`: add to ERP<br>`to_date`: add to ERP | — |
| `payroll_date_settings` | 1 | 🟡 | `payrollUseAttendancy`→`use_attendance`, `isActive`→`is_active` | `overtime`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1)<br>`allowance`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1)<br>`deduction`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1)<br>`loan`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1)<br>`leave_deduction`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1)<br>`attendance`: `overtime/allowance/deduction/loan/leave_deduction/attendance` → `use_*` booleans; `payrollUseAttendancy` → company default **time source** (W-1) | `company_id`: Kairuki company id<br>`use_overtime`: from HRMS flag columns<br>`use_loan`: from HRMS flag columns<br>`use_allowance`: from HRMS flag columns<br>`use_leave_deduction`: from HRMS flag columns<br>`use_deduction`: from HRMS flag columns |

### Level 4 — transactions

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `emp_leaves` | 265 | 🟡 | `hod_isApproved`→`hod_is_approved`, `hr_office_isApproved`→`hr_office_is_approved`, `isReported`→`is_reported`, `isActive`→`is_active` | `jina_la_marehem`: → new `emp_leave_details` table with English names (L-5): deceased_name, relationship, death_date, marriage_certificate, terms_accepted<br>`uhusiano_na_marehem`: → new `emp_leave_details` table with English names (L-5): deceased_name, relationship, death_date, marriage_certificate, terms_accepted<br>`death_date`: → new `emp_leave_details` table with English names (L-5): deceased_name, relationship, death_date, marriage_certificate, terms_accepted<br>`cheti_cha_ndoa`: → new `emp_leave_details` table with English names (L-5): deceased_name, relationship, death_date, marriage_certificate, terms_accepted<br>`term_condition`: → new `emp_leave_details` table with English names (L-5): deceased_name, relationship, death_date, marriage_certificate, terms_accepted | `company_id`: Kairuki company id<br>`employee_dependant_id`: null (or from compassionate details) |
| `annual_leave_rosters` | 1,873 | ⚠️ | `isActive`→`is_active`, `hod_isApproved`→`hod_is_approved`, `hr_office_isApproved`→`hr_office_is_approved`, `isReported`→`is_reported` | `term_condition`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`reported_to_hr`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`days_passed`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`days_changed`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`changed_end_date`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`changed_start_date`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`hod_last_edit_date`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`hr_last_edit_date`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`emp_last_edit_date`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr<br>`leave_year_remark`: add columns to ERP (L-7): date-change tracking, terms, reported_to_hr | `company_id`: Kairuki company id |
| `absence_requests` | 177 | ✅ | — | — | `company_id`: Kairuki company id |
| `emp_allowances` | 187 | 🟡 | `isActive`→`is_active` | — | `company_id`: Kairuki company id<br>`allowance_category_id`: from `department_allowances.category_id` |
| `daily_allowances` | 275,642 | ✅ | `isActive`→`is_active` | — | `company_id`: Kairuki company id |
| `overtimes` | 2,547 | ⚠️ | `isPaid`→`is_paid`, `isActive`→`is_active` | `punch_in_late_minutes`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`punch_out_late_minutes`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`attendance_checkin`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`attendance_checkout`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`amount_per_duration`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`is_hos_approved`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`hos_approved`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`hos_comment`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`created_by`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`updated_by`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by`<br>`company_branche_id`: add HOS level + attendance fields to ERP (OT-4, OT-2): `hos_approved`, `is_hos_approved`, `hos_comment`, `amount_per_duration`, `attendance_checkin/out`, `punch_*_late_minutes`, `created_by`, `updated_by` | `company_id`: Kairuki company id |
| `employee_loans` | 101 | ⚠️ | `isActive`→`is_active` | `employeeApproved`: add `employee_approved` to ERP | `company_id`: Kairuki company id |
| `employee_mothly_advances → employee_monthly_advances` | 0 | 🟡 | `isActive`→`is_active` | — | `company_id`: Kairuki company id<br>`created_by`: null<br>`month`: from `date_advanced`<br>`year`: from `date_advanced` |
| `salary_adjustments` | 81 | ✅ | — | — | `company_id`: Kairuki company id |

### Level 5 — payroll history

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `payroll_sheets` | 23 | ⚠️ | `hrApproved`→`hr_approved`, `payrollApproved`→`payroll_approved` | `directorAproved`: → `director_approved` (typo)<br>`approved_at`: add to ERP<br>`created_by`: add (audit)<br>`updated_by`: add (audit)<br>`company_branche_id`: drop | `company_id`: Kairuki company id<br>`director_approved`: from `directorAproved` |
| `payroll_sheet_details` | 9,065 | ✅ | `isActive`→`is_active`, 11 payroll columns to lower case | — | — |
| `allowance_sheets` | 121,558 | ✅ | — | — | — |
| `overtime_sheets` | 14 | ✅ | — | — | — |
| `leave_deductions` | 2,839 | ⚠️ | `isActive`→`is_active` | `created_by`: add or drop<br>`updated_by`: add or drop<br>`company_branche_id`: drop | — |
| `loan_histories` | 1,170 | ✅ | `out_standing_loan`→`outstanding_loan` | — | — |
| `saccos_contributions` | 2,345 | ⚠️ | — | `total_contribution`: add to ERP | — |
| `payroll_comments` | 4 | ✅ | — | — | — |

### Level 6 — attendance history (large)

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `attendance_transas` | 534,189 | ⚠️ | `hasOvertime`→`has_overtime`, `isClaimed`→`is_claimed` | `area_alias`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop<br>`in_area_alias`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop<br>`punch_in_late_hour`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop<br>`punch_out_late_hour`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop<br>`payroll_date_remark`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop<br>`status`: add `area_alias`, `in_area_alias`, `payroll_date_remark`, `status` to ERP. `punch_*_late_hour` derivable from minutes → drop | `company_id`: Kairuki company id |
| `attendance_claims` | 120 | ✅ | `attendancy_transa_id`→`attendance_transa_id`, `setLostHrZero`→`set_lost_hr_zero` | — | — |

### Other modules (low priority)

| HRMS table → ERP | Rows (local) | Status | Renames | HRMS-only columns → decision | ERP-only columns → fill |
|---|---|---|---|---|---|
| `messagings` | 418 | ✅ | — | — | `company_id`: Kairuki company id |
| `message_recipients` | 469 | ⚠️ | — | `uuid`: add or drop<br>`individual_message`: add to ERP (message text per recipient) | — |
| `assets` | 39 | ⚠️ | — | `section_id`: add to ERP or drop (39 rows)<br>`maintenance_schedule_id`: drop (maintenance not migrated) | `company_id`: Kairuki company id |
| `asset_categories` | 6 | ✅ | — | — | `company_id`: Kairuki company id |
| `asset_locations` | 12 | ✅ | — | — | `company_id`: Kairuki company id |
| `suppliers` | 1 | ⚠️ | — | `employee_id`: map `phone_number`→`phone`, `physical_address`→`address`; `contact_person` add; `employee_id` drop (1 row)<br>`contact_person`: map `phone_number`→`phone`, `physical_address`→`address`; `contact_person` add; `employee_id` drop (1 row)<br>`phone_number`: map `phone_number`→`phone`, `physical_address`→`address`; `contact_person` add; `employee_id` drop (1 row)<br>`physical_address`: map `phone_number`→`phone`, `physical_address`→`address`; `contact_person` add; `employee_id` drop (1 row) | `company_id`: Kairuki company id<br>`code`: **decide**<br>`phone`: **decide**<br>`address`: **decide**<br>`tax_number`: **decide** |
| `accounts` | 0 | 🟡 | — | `company_branche_id`: drop (accounting not used in HRMS, 0 rows) | `company_id`: Kairuki company id<br>`parent_id`: n/a (0 rows)<br>`code`: n/a (0 rows)<br>`description`: n/a (0 rows)<br>`is_active`: n/a (0 rows) |
| `journal_entries` | 0 | 🟡 | — | `transanction_id`: drop (0 rows, different design)<br>`debit_account_id`: drop (0 rows, different design)<br>`debit_amount`: drop (0 rows, different design)<br>`credit_account_id`: drop (0 rows, different design)<br>`credit_amount`: drop (0 rows, different design)<br>`entry_date`: drop (0 rows, different design) | `company_id`: Kairuki company id<br>`reference_no`: n/a (0 rows)<br>`date`: n/a (0 rows)<br>`description`: n/a (0 rows)<br>`status`: n/a (0 rows)<br>`created_by`: n/a (0 rows) |

## 4. HRMS tables with no ERP table yet

| HRMS table | Rows (local) | Plan / gap item |
|---|---|---|
| `adjusting_entries` | 0 | drop (0) |
| `admitted_employees` | 314 | A-6 / P-8 |
| `allowance_sheets_backup_20260208` | 121,044 | not migrated |
| `appraisal_kpi_assesments` | 0 | 0 rows — drop |
| `appraisal_kpi_attributes` | 0 | 0 rows — drop |
| `asset_comments` | 1 | low priority |
| `asset_documents` | 0 | low priority |
| `attendance_sync_days` | 3 | A-1 |
| `audit_logs` | 74 | #13 (migrate once ERP audit log exists) |
| `authentication_log` | 1,977 | archive |
| `biotime_punch_logs` | 1,168 | A-1 (attendance adapter) |
| `carousel_slides` | 5 | not migrated |
| `cities` | 150,309 | reseed |
| `countries` | 250 | reseed |
| `currencies` | 250 | reseed |
| `department_overtimes` | 45 | **OT-1** overtime rates — create ERP table |
| `emp_contract_comments` | 944 | E-2 contract comment trail |
| `employee_experiances` | 6 | → `employee_experiences` (typo; ERP table exists — map columns) |
| `employee_licences` | 3 | E-10 |
| `employee_patient_ids` | 232 | E-14 external IDs |
| `employee_settings` | 413 | E-12 per-employee settings |
| `employee_timetables` | 276,291 | **R-1** daily duties — create ERP table |
| `excuse_of_duties` | 345 | A-6 / P-8 |
| `head_of_deaprtments` | 109 | → `head_of_departments` (typo; ERP table exists — map columns) |
| `internship_periods` | 146 | #10 |
| `languages` | 183 | reseed |
| `learning_categories` | 2 | L&D module (optional) |
| `learning_documents` | 2 | L&D module (optional) |
| `leave_family_details` | 0 | L-5 (0 rows) |
| `maintanance_logs` | 1 | low priority |
| `maintenance_comments` | 0 | low priority |
| `maintenance_schedules` | 2 | low priority |
| `modules` | 11 | ERP has its own `erp_modules` |
| `notification_messages` | 0 | drop (0) |
| `notifications` | 2,508 | decide: migrate unread only, or start fresh |
| `other_deductions` | 0 | P-15 (0 rows) |
| `payroll_change_logs` | 0 | P-5 (0 rows) |
| `permission_categories` | 18 | → Spatie / drop |
| `permissions` | 136 | → Spatie |
| `proffesional_licences` | 3 | E-10 (check vs employee_licences) |
| `resident_periods` | 9 | #10 |
| `roaster_emp_duties` | 7,000 | R-1 (check if still used) |
| `role_audit_log` | 0 | archive |
| `roles` | 20 | → Spatie (section 2) |
| `roles_permissions` | 749 | → `role_has_permissions` |
| `roster_day_duties` | 1,082 | R-1 |
| `states` | 5,000 | reseed |
| `system_settings` | 0 | drop (0) |
| `timezones` | 428 | reseed |
| `transanctions` | 0 | drop (0) |
| `uploaded_payroll_sheets` | 11,970 | P-13 |
| `uploaded_payrolls` | 32 | P-13 payroll import |
| `users_permissions` | 32 | → `model_has_permissions` |
| `users_roles` | 805 | → `model_has_roles` |
| `workflow_instance_steps` | 197 | #11 |
| `workflow_instances` | 92 | #11 |
| `workflow_steps` | 20 | #11 |
| `workflow_templates` | 7 | #11 |
| `workflow_types` | 7 | #11 |

## 5. Next steps

1. Resolve every **decide** / **check** above with HR/admin (most are small).
2. Add the ERP columns/tables marked "add" — each is tied to a gap-list item.
3. Build the migration as an artisan command in the ERP (`erp:import-hrms --source=hrms_copy`) using a second DB connection, one class per level, with the verification checks from section 1.
4. Dry-run it against a production copy; fix mismatches until counts and checksums match.
