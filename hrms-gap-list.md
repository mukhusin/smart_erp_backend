# HRMS → Smart ERP Gap List

Scope of this pass: features HRMS gained **after smart-erp's last commit (2026-04-28)**,
from 93 HRMS commits up to 2026-11-05. Each item was checked against the smart-erp code.
Features both systems had before April are **not** compared here yet (next pass, per module).

Status: ❌ missing · 🟡 partial / different · ✅ present

## Summary: what to build before the parallel payroll run

All six core HR modules are compared below. This collects every module's "minimum" list into one
build order. It assumes the first parallel run is in **Schedule mode** (no attendance device needed).

| Step | Build | Items |
|---|---|---|
| 0. Architecture | Split into `backend/` (API only) and `frontend/` (Vue SPA), API contract, shared component library. Everything below is built API-first (see `architecture.md`) | — |
| 1. Foundations | Workflow engine, audit log, employee logins | #11, #13, E-9 |
| 2. People & contracts | Employee numbering (keep HRMS numbers), waiting contracts, contract edit, end statuses, internship/residency | #6, E-1, E-2, E-3, #10 |
| 3. Time without devices | Work entries + default working schedule, duty assignment, per-employee settings | W-1, R-1, R-2, E-12 |
| 4. Leave | Day calculation + validation, entitlement & balances, statuses, unpaid leave → payroll | L-2, L-6, L-9, L-10 |
| 5. Overtime & allowances | Overtime rates, requests, approval; allowance rates and approval; overtime limited to the period | OT-1, OT-3, OT-4, OT-5, AL-2, AL-3 |
| 6. Payroll | Mode A on work entries, pro-rata, loan screens, balances changed only on approval | P-1, P-3, LN-1, LN-2 |
| 7. Outputs | Statutory reports, bank export | P-9, P-10 |
| 8. Data migration | Repeatable HRMS → ERP scripts (see "Next passes") | per-module migration notes |

**After the parallel run starts:** attendance engine and integrations (A-1–A-3, OT-2, AL-4, #1),
activity-based allowances (P-6), contract renewal requests (E-17), remaining reports and screens.

## P1: needed before HR/payroll can switch to the ERP

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| 1 | BioTime attendance integration: queue-based sync and backfill that survives outages (`emp_code` = employee id) | ❌ | ERP has manual attendance and claims only, no device sync. No attendance means no attendance deductions |
| 2 | Unpaid leave deducted from basic salary, itemised on payslip, note on PDF slip | ❌ | Not in `PayrollComputationService` |
| 3 | Leave deduction on/off setting (with disabled state shown) | ❌ | |
| 4 | NHIF/NSSF rates read from payroll settings | 🟡 | ERP computes statutory items. Check that the rates come from settings, not constants |
| 5 | `payroll_date_remark`: tracks payroll day/date changes | ❌ | |
| 6 | Employee numbers by type: ST (full time), PT, VOL, INT/KH/LAB, INT/KH/NRS, INT/KH/OTHER, RES/KH/SN; temporary number until first contract | ❌ | ERP has a single generic `employee_no`. **Migration must keep existing HRMS numbers**, staff and payroll use them |
| 7 | Contract lifecycle: `waiting` (future renewal), `ended`, previous contract deactivated, number given to waiting contract | 🟡 | ERP statuses are only active / pending / expired |
| 8 | Permanent contract: period worked out automatically up to retirement age | ❌ | |
| 9 | Contract-expiry SMS skipped when a waiting renewal exists | 🟡 | ERP has a contract status command. Check the SMS rule |
| 10 | Internship and residency periods (start, months, end, active), separate from contracts | ❌ | |
| W-1 | Work entries: one record per employee per day from the roster/default schedule or from attendance, read by payroll, allowances and overtime | ❌ | New design (see work entries section). Lets payroll and allowances run without any attendance device |

## P2: process and control

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| 11 | Dynamic workflow engine: configurable steps, custom action labels, notifications with links, my-approvals page | ❌ | ERP uses fixed approval columns (`hod_approved`, `hr_approved`, `director_approved`, `finance_approved`) |
| 12 | Payroll sheet refresh blocked once its workflow is approved | ❌ | Depends on #11 |
| 13 | Audit log for employee and payroll models (old/new values, who, IP) + contract history view | ❌ | |
| 14 | Remove wrong contract: restore previous number and type, free the number, give a new one if it's taken | ❌ | Depends on #6 |
| 15 | Leave statuses `ended` (replaces `reported`), LWP rules, other-approved-leave handling | 🟡 | ERP leave statuses: pending / approved / rejected |
| 16 | Abscondment (replaces vacation of office) | ❌ | |
| 17 | Annual leave roster moves with the employee on department change | ❌ | ERP has rosters, but no move on department change |
| 18 | Attendance report limited by role, department and section, HR officer permission check | 🟡 | ERP has `section_id`. Check the report rules |
| 19 | Salary adjustment approvals (HR → Director → Finance) | 🟡 | ERP has `SalaryAdjustment`. Check the 3-level flow and SDL/permission fixes from 2026-06-22 |

## P3: operations and user experience

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| 20 | Auto logout when idle | 🟡 | Only the session lifetime in config |
| 21 | No cached pages after logout (Back button sends user to login) | ❌ | |
| 22 | Daily log files + 7-day cleanup | 🟡 | Check the logging config and scheduler |
| 23 | Payroll summary vs previous month: difference explanation, "New Payroll Entries", sticky header | ❌ | |
| 24 | Contract types can be enabled or disabled | 🟡 | Check the UI |
| 25 | Document upload limits, profile photo toast, WCF number prefill | ❓ | Small fixes, check during UI review |

## Module pass: Payroll (checked 2026-11-05)

**What already matches:** the payslip structure. ERP `payroll_sheet_details` has every HRMS column
(basic, allowances, overtime, leave/attendance deduction, loans, SACCOS, NSSF/NHIF employee+employer,
HESLB, TUGHE, PAYE, EF, NIC, relief, WCF, SDL, bank fields), and `PayrollComputationService` computes the
statutory items, loan repayment and SACCOS, with a `Cache::lock`. Payroll settings columns also match,
with clearer `use_*` toggles. The data migration for payslip history is therefore mostly a rename
(`NSSF` → `nssf`, `isActive` → `is_active`).

The gaps are in **what feeds the payroll** and **what comes out of it**:

### Inputs: how the numbers are produced

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| P-1 | Daily basic salary / payroll days (`app:daily-basic-salary-command`, `payroll:calculate-days`): pro-rata pay for joiners, leavers and date changes | ❌ | ERP uses `number_of_days` on the sheet only. **Risk: wrong pay for anyone who joins or leaves mid-month** |
| P-2 | Attendance-based payroll (`payrollUseAttendancy`) + `app:calculate-attendance` (worked hours, %) → attendance deduction | ❌ | Column exists, nothing computes it. Needs BioTime (#1) |
| P-3 | Leave deduction feeding payroll (`leave_deductions`, group sync) | 🟡 | ERP has the `leave_deductions` table but the payroll engine never reads it (overlaps #2) |
| P-4 | Overtime generated from attendance against the duty roster (`overtime:generate`, twice a day) | ❌ | Needs duties + attendance (OT-2). In Mode A overtime comes from approved requests instead (OT-3/OT-4) |
| P-5 | Daily allowance auto-update + dedupe (`app:daily-allowance-command`, `daily-allowances:deduplicate`) | ❌ | |
| P-6 | Theatre allowance = rate × daily surgery/procedure cases (`app:theater-allowance`) | ❌ | Case count comes from an external system (Afyabox at Kairuki). See activity-based allowances below |
| P-7 | Night allowance | ❌ | HRMS schedule commented out. Check whether it's still used |
| P-8 | Excuse-of-duty (`app:excuse-of-duty`) and admitted staff (`app:admitted-employee-command`) affecting attendance | ❌ | Read from Afyabox today. Must be an optional source with manual entry |

### Outputs: reports, exports, control

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| P-9 | Statutory reports: PAYE, NSSF, NHIF, WCF, HESLB, TUGHE, NIC, SDL (+ PAYE/SDL Excel exports) | ❌ | Needed for monthly filing |
| P-10 | Bank payment export (payroll sheet export, bank details list) | ❌ | Needed to actually pay salaries |
| P-11 | Payroll variance / compare two payrolls (+ Excel export) | ❌ | |
| P-12 | Sheet summary + more-detail views | 🟡 | ERP has a sheet `show`. Check whether the summary totals are there |
| P-13 | Upload / import a payroll sheet, re-import, download the file | ❌ | |
| P-14 | Payroll approval through the workflow engine (start-workflow / approve) | 🟡 | ERP has fixed `hr/payroll/director_approved` flags (see #11) |
| P-15 | Management screens for NHIF/NSSF/WCF members, SACCOS members + contribution history, reliefs, other deductions | 🟡 | ERP has the models and a deductions index, but no full CRUD screens (known gap) |
| P-16 | Payroll settings screen | 🟡 | Model exists, no settings view (known gap) |
| P-17 | Employee self-service payslip | ✅ | `SelfServiceController` + payslip view |
| P-18 | Loans, loan providers, advances | ✅ | Check loan history per payslip during the parallel run |

### Design requirement: two payroll modes (payroll must run without attendance)

HR must be able to run payroll even when there is no attendance data. Other well-known
payroll systems do this by treating salaried staff as working their schedule unless told otherwise.
For example, Odoo picks per contract whether work comes from the *working schedule* or from
*attendance*, and Workday, SAP and Sage pay salaried staff a fixed amount and record absences as exceptions.

| | **Mode A: Salary-based** (default) | **Mode B: Attendance-based** |
|---|---|---|
| Basis | Full monthly salary, employee assumed to work their schedule | Days/hours actually recorded by BioTime |
| What reduces pay | Exceptions only: unpaid leave, absences HR records by hand, joiners/leavers pro-rated | Missing days/hours from attendance, plus unpaid leave |
| Needs BioTime | No | Yes |
| Fits | Office, admin and management staff; any time devices or sync are down | Shift/roster staff where attendance is enforced |

Rules for the ERP:
1. **Mode is chosen per employee (or per contract / pay group)**, with a company default. This is how HRMS
   already works (global `payrollUseAttendancy` + per-employee `hasAttendance` override), so keep it.
2. **Missing attendance must never silently become a deduction.** If a Mode B employee has no attendance
   data for part of the period (device or sync outage), flag it on the sheet. HR then confirms either
   "pay as Mode A for these days" or "deduct".
3. **Pro-rata method is a setting:** calendar days of the month, or a fixed 30-day month. It applies to
   joiners, leavers and unpaid leave in both modes (P-1).
4. **The mode used is shown** on the payroll sheet line and on the payslip, so differences can be explained.
5. ERP status: ❌. `use_attendance` exists in `PayrollDateSetting` but `PayrollComputationService` ignores
   it, and there is no per-employee override.

How both modes are implemented consistently for payroll, allowances and overtime: see **Design requirement: work entries** below.

**Payroll verdict:** the calculation is ready, but it is fed with incomplete inputs.
With Mode A in place, the parallel payroll run **does not have to wait for BioTime**.
Minimum to start it: **Mode A + P-1 (pro-rata), P-3 (leave deduction), P-9 (statutory reports), P-10 (bank export)**,
plus, if overtime and allowances are paid that month, overtime rates/requests/approval (OT-1, OT-3, OT-4) and allowance rates/approval (AL-2, AL-3).
Mode B (P-2 attendance, #1 attendance sync, P-8 external feeds) can follow, and attendance-based staff are added once it's ready.

## Module pass: Employee + Contract (checked 2026-11-09)

**What already matches:** the employee profile. ERP `employees` has the same fields as HRMS (names,
IDs, NSSF/NHIF/WCF numbers, department/section/position, `emp_type`, probation dates, termination date),
plus `company_id`. Profile tabs exist for qualifications, experience, dependants, documents,
bank accounts and contracts. Departments, positions, salary scales and contract types have full CRUD,
and contract types carry the same flags (`has_payroll`, `has_overtime`, `has_allowance`,
`has_annual_leave`, `has_emergency_fund`) plus `template_content`.

### Contracts

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| E-1 | Future-dated contract waits (`waiting`) and the current one keeps running until it starts | ❌ | **Bug-level:** ERP `storeContract` always activates the new contract and marks the running one `superseded` at once. An early renewal would cut the current contract. `contract:status` can activate `pending` contracts, but nothing ever creates them |
| E-2 | Contract adjustment: edit with `date_of_change`, change of type → new number, comment trail (`emp_contract_comments`) | ❌ | ERP has no contract edit/update route, only create |
| E-3 | Contract end statuses: ended, retired, deceased, inactive, abscondment, with `termination_date` / `death_date` | 🟡 | ERP statuses: active / superseded / expired. No `death_date` column |
| E-4 | Contract PDF from templates (specific-time, special contract) | 🟡 | ERP stores `template_content` but nothing generates a PDF |
| E-5 | Contract expiry SMS (3 months before, skipped when a renewal is waiting) | ❌ | See #9 |
| E-6 | HR contract lists by status, employees with no contract, retired, near retirement, left the company | ❌ | ERP employee list filters by `employment_status` only |
| E-7 | Retirement: near-retirement list, permanent contract period up to retirement age | ❌ | See #8 |
| E-8 | Employee numbering, internship/residency periods, remove wrong contract, salary adjustment | ❌/🟡 | Already listed: #6, #10, #14, #19 |

### Employee

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| E-9 | **Login created on registration** (username = email, role `normal_employee`) | ❌ | ERP never creates a `User` for an employee, so new staff can't use self-service |
| E-10 | Professional licences + practicing licence report | ❌ | Important in a hospital: licence numbers and expiry dates. Should alert before expiry |
| E-11 | Supervisor assignment, HOD set as supervisor automatically on registration | 🟡 | ERP has `EmpSupervisor` model, no UI or auto-assignment |
| E-12 | Per-employee settings: `hasAttendance`, `hasAnnualLeave` | ❌ | Needed for payroll Mode A/B per employee |
| E-13 | Registered on attendance device at creation, "send to biometric" | ❌ | Do it through the `AttendanceSource` adapter, only when switched on |
| E-14 | Patient ID (staff member's record number in the HMS, used for excuse-of-duty) | ❌ | Make it a generic "external IDs" list on the employee (system → ID), not an HMS field |
| E-15 | Reports: employee export with chosen columns, phone numbers, birthdays, emails, termination report, employed report | 🟡 | ERP reports page has some export. Check each report |
| E-16 | Audit trail / contract history view | ❌ | See #13 |

### New feature: employee contract renewal request (not in HRMS)

HRMS today only gives HR a "near to end" contract list and sends a 3-month expiry SMS.
The employee cannot ask for renewal in the system. The ERP should let them, through the dynamic workflow.

| # | Requirement | ERP |
|---|---|---|
| E-17 | Employee contract renewal request with configurable workflow, comments, attachments, timesheet and previous-contract view | ❌ New. Depends on #11 (workflow engine), E-1 (waiting contracts), E-5 (expiry notice) |

**1. When the employee can request**
- The current contract is active and ends within **N days**, a company setting with **default 90**.
- Not shown for permanent contracts.
- Not shown when a renewal request is already open or a renewal contract is already waiting.
- At N days the employee gets an SMS/email/in-app notice with a link to the request form. This replaces today's plain 3-month SMS.
- **Two ways to start a renewal (agreed 2026-11-11):**
  - **HR initiates** the renewal for a specific employee, or
  - the **employee requests** it from self-service.
  Both go through the same dynamic approval workflow. They exclude each other: while the employee has an open
  renewal request, HR cannot start one for that employee, and while HR's renewal is open the employee cannot request.

**2. Request form (self-service)**
- Comment: why they want to renew, any changes requested.
- Optional proposed period and contract type. HR decides the final terms.
- **Attachments:** several files (PDF/images). Allowed types and size are settings. Files are stored as the employee's documents and linked to this request and contract.
- **Timesheet:**
  - attendance on: the system attaches a summary for the current contract period (days worked, absences, leave taken, late arrivals)
  - attendance off: the employee uploads a timesheet file
  - both can be attached

**3. Approval through the dynamic workflow**
- New workflow module **"Contract renewal"**. Steps are configured in the workflow settings (e.g. Supervisor → HOD → HR → Director), not hardcoded.
- At each step the approver can approve, reject or return it to the employee for changes, and add a comment and attachments. A comment is required to reject or return.
- Everyone involved is notified at each step, with a link.
- Reminders and escalation are based on the **contract end date**, not just the request age, so nothing is left until after the contract has ended.

**4. What approvers see**
- the request: comment, attachments, timesheet
- **previous contracts of this employee, when there are any:** type, number, start/end, period, basic salary, how it ended, earlier renewal requests with their decisions, comments and attachments
- when available: leave and attendance summary and appraisal results for the current contract (new appraisal design: architecture.md §17)
- read-only, visible only to users who have a step in this request, and to HR

**5. Result**
- **Approved:** HR creates the renewal contract as **`waiting`** (E-1), prefilled from the request. It starts automatically the day after the current contract ends. The employee number stays the same if the employment type stays the same.
- **Rejected:** the employee is notified with the reason, and HR gets a task to handle the contract end.
- Every action goes into the audit log (#13).

**6. Data (suggested)**
- `contract_renewal_requests`: `company_id`, `employee_id`, `contract_id`, `proposed_nature_of_contract_id`, `proposed_period`, `comment`, `status`, `new_contract_id`
- uses the `HasWorkflow` trait, as leave, overtime, allowances, salary adjustments and attendance claims already do in HRMS
- files in a shared attachments table (polymorphic), reused for employee documents and workflow step attachments

### Data migration notes (Employee + Contract)
- `employees.st_no` → `employee_no`, **values kept unchanged**. Disable ERP number generation for migrated staff.
- `company_id` = Kairuki for all rows. `company_branche_id` → `company_branch_id`.
- `isActive` → `is_active`, `hasX` → `has_x`, `employeement_type` → `employment_type` (typo fixed).
- Contract status mapping: `active` → active, `waiting` → pending (needs E-1 first), `ended` → expired.
  `retired`, `deceased`, `inactive` need ERP statuses first (E-3).
- `employee_settings`, `internship_periods`, `resident_periods`, `employee_licences`, `employee_patient_ids`
  have no ERP table yet.

**Employee + Contract verdict:** the profile is ready. The contract lifecycle is not. E-17 (renewal request) is new and can come after migration, once the workflow engine and waiting contracts are in place. Before migrating
real data, at least **E-1 (waiting contracts), E-2 (contract edit), E-3 (end statuses), E-9 (logins)
and #6 (numbering)** are needed. E-1 is the most dangerous: entering a renewal early would end the
running contract straight away.

## Module pass: Attendance + Roster (checked 2026-11-09)

**What already matches:** the setup tables. ERP has roster codes (shifts: times, work hours,
can punch, pass day), timetables (check-in/out windows, cross-day, `use_attendance`), rosters (header with
department/section, dates, prepared/approved by), public holidays, claim reasons, absence requests,
attendance claims, and an `attendance_transas` table with nearly the same columns as HRMS.
CRUD screens exist for codes, timetables, rosters and claims.

**What is missing is the engine:** in the ERP, no employee is ever assigned to a duty, and nothing
fills `attendance_transas`. The attendance page lists an empty table.

### Roster (duties)

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| R-1 | **Daily duty per employee** (`employee_timetables`: employee, date, roster code/shift, start/end, can punch, pass day, overtime + max overtime hours, source) | ❌ | **Core gap:** an ERP roster is only a title and date range. Without this there is no overtime from the roster (P-4), no expected hours for attendance, no "my duty roster" |
| R-2 | Bulk duty assignment (several employees × duties × dates, with preview) + single assignment with overtime | ❌ | |
| R-3 | Roster per department/section with prepared → approved status, department roster settings page | 🟡 | Columns exist. Approval should use the workflow engine (#11) |
| R-4 | Roster codes (shifts) | ✅ | |
| R-5 | Timetables + daily generation of employee timetables (`app:timetable-command`) | 🟡 | Timetable CRUD exists. Nothing generates the daily duties |
| R-6 | Roster export (Excel/PDF), filter by section | ❌ | |
| R-7 | Self-service "my duty roster", HOD roster view | 🟡 | Methods exist, but there's nothing to show until R-1 |
| R-8 | Public holidays | ✅/❓ | CRUD exists. Check how holidays affect attendance and overtime |

### Attendance

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| A-1 | Punch collection: queued device sync, punch log, per-day sync status, backfill after outages | ❌ | Through the `AttendanceSource` adapter (see integrations) |
| A-2 | **Daily attendance calculation** (`ProcessAttendanceDayJob`, `app:calculate-attendance`): match punches to the day's duty → in/out, late minutes, early leave, lost hours, worked hours, %, overtime minutes, night shifts over midnight | ❌ | **Core gap:** the table exists, nothing fills it. Needed for payroll Mode B |
| A-3 | Recalculate attendance for a date range after corrections (HR "refresh") | ❌ | |
| A-4 | Lost-hours claims: reason, HR accepts, set lost hours to zero, through the workflow | 🟡 | ERP has claim CRUD. Check that approval updates `lost_hour`/payroll, and move it onto the workflow engine (#11) |
| A-5 | Absence requests | ✅/❓ | ERP `absence_requests` exists. Check it against HRMS behaviour |
| A-6 | Excuse-of-duty and admitted staff | ❌ | Manual entry + optional external source (P-8) |
| A-7 | Reports: compiled report with filters + Excel, summary, statistics, per-employee detail, director view, internship attendance, lost-hours claims list | ❌ | ERP `AttendanceController` only has an index |
| A-8 | Device screens: devices, areas, punch transactions, employees not enrolled on a device (+ PDF) | ❌ | Part of the attendance integration screens |
| A-9 | Visibility by role/department/section | 🟡 | See #18 |

### Data migration notes (Attendance + Roster)
- `roster_codes`, `roster_timetables`, `rosters`, `public_holidays`, `attendance_claim_reasons`: same columns, add `company_id`, `isActive` → `is_active`.
- `employee_timetables` (daily duties) and `roster_day_duties`: **no ERP table yet** (R-1).
- `attendance_transas` history maps almost 1:1 (`isClaimed` → `is_claimed`, `hasOvertime` → `has_overtime`).
  The ERP lacks `area_alias`, `in_area_alias`, `punch_in_late_hour`, `punch_out_late_hour`, `payroll_date_remark`.
- `attendance_claims`: `attendancy_transa_id` → `attendance_transa_id`, `setLostHrZero` → `set_lost_hr_zero`.
- `excuse_of_duties`, `admitted_employees`, `biotime_punch_logs`, `attendance_sync_days`: no ERP table.

**Attendance + Roster verdict:** the setup exists, the engine doesn't. **R-1 + R-2 (duty assignment)**
are needed even for payroll Mode A whenever overtime is paid from the roster (P-4). **A-1 to A-3 (punches and
daily calculation)** are needed for Mode B. Until then the ERP can't replace HRMS attendance.

## Module pass: Leave (checked 2026-11-09)

**What already matches:** the tables. ERP `leave_types` (gender, with pay, max days), `emp_leaves`,
`annual_leave_rosters` and `leave_deductions` have nearly the same columns as HRMS. There are screens for
leave types, leave requests (HR approve/reject), HOD approval in the HOD portal, annual leave rosters,
absence requests and public holidays. `leave:status` and `leave:annual-balance` commands exist.

**What is missing is the rules:** the ERP accepts whatever number of days the user types, checks nothing,
and never gives anyone an annual entitlement.

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| L-1 | Leave types with gender, max days, with/without pay | ✅ | |
| L-2 | System calculates the days and checks the request: balance, max days, gender, overlap with other leave, contract has annual leave | ❌ | ERP `store` takes a typed `days_requested` and validates nothing |
| L-3 | Approval HOD → HR (workflow on `EmpLeave`) | 🟡 | ERP has separate HOD and HR approve actions on fixed columns. Move to the workflow engine (#11) |
| L-4 | HR requests leave on behalf of an employee | 🟡 | ERP form takes `employee_id`. Check who can use it |
| L-5 | Special leave details: compassionate (deceased's name, relationship, date of death, dependant), marriage certificate, maternity/paternity children (`leave_family_details`) | ❌ | ERP only has `employee_dependant_id` |
| L-6 | **Annual entitlement and balance:** 28 days a year, last year's balance used first, leave split over several entries, recalculated for previous and current year | ❌ | **Wrong balances:** ERP never sets `current_balance`, so `leave:annual-balance` subtracts used days from 0 |
| L-7 | Annual leave roster planning: employee proposes dates, HOD/HR approve, date changes tracked (`changed_start/end_date`, `days_changed`, edit dates), postpone, terms & conditions accepted | 🟡 | ERP has roster CRUD + approve/reject only |
| L-8 | Roster entries move with the employee on department change | ❌ | See #17 |
| L-9 | Report back from leave (`report_date`, reported to HR), statuses `ended` (replaced `reported`) and `postponed` | 🟡 | ERP still uses `reported`. See #15 |
| L-10 | Unpaid leave → `leave_deductions` → payroll, group sync | 🟡 | Table only. See P-3 / #2 |
| L-11 | Views: employee history incl. last year, leave progress, leave form PDF, department and HR lists by status, director "on leave" list, actual balance page | 🟡 | ERP has basic lists only |
| L-12 | Absence requests (with per diem) | ✅/❓ | Check behaviour against HRMS |

### Design requirement: configurable leave rules
HRMS has these rules fixed in code (28 days, calendar days, carry-over used first). The ERP should
make them company settings, ideally per contract type, the way established HR systems handle leave allocation:
- **Entitlement:** days per year (default 28), given yearly or built up monthly, pro-rata for joiners and leavers.
- **Carry-over:** allowed or not, maximum days carried over, expiry date of carried days, used before new days (HRMS behaviour).
- **Day counting:** calendar days (HRMS today) or working days, and whether weekends and public holidays count.
- **Per leave type:** max days per request or year, gender, paid/unpaid/partly paid (e.g. sick leave first/second period), documents required.
- **Eligibility:** only when the contract type has `has_annual_leave`, and the per-employee `hasAnnualLeave` setting (E-12).

### Data migration notes (Leave)
- `emp_leaves` maps nearly 1:1. HRMS-only columns: `cheti_cha_ndoa`, `jina_la_marehem`, `uhusiano_na_marehem`,
  `death_date`, `term_condition`, and `leave_family_details`. Give them English names in a details table (L-5).
- `annual_leave_rosters`: the ERP lacks `changed_start_date`, `changed_end_date`, `days_changed`, `days_passed`,
  `*_last_edit_date`, `leave_year_remark`, `reported_to_hr`, `term_condition`.
- Status `reported` → `ended`. `hod_isApproved` → `hod_is_approved` etc.
- **Migrate balances as they are** (`last_year_balance`, `current_balance`, `actual_balance`). The ERP can't
  recalculate them until L-6 exists, and staff will check their balance on the first day.

**Leave verdict:** the tables are ready, the rules are not. Before migration, at least **L-2 (calculate and
validate days), L-6 (entitlement and balance), L-9 (statuses) and L-10 (unpaid leave to payroll)** are needed.
Without L-6, every employee would see a zero or wrong annual leave balance.

## Module pass: Allowance + Overtime (checked 2026-11-09)

**What already matches:** the tables. ERP has `allowance_categories` (same flags: duration, claimed,
deduct_leave, other_absence), `department_allowances` (rate per department/section/position),
`emp_allowances`, `daily_allowances`, `allowance_sheets`, `overtimes` and `overtime_sheets`. There are CRUD
screens for categories, employee allowances and daily allowances. The payroll engine adds HR-approved
allowances and overtime, records allowance/overtime sheets and sets `is_paid`.

**What is missing:** overtime can't be created, approved or priced in the ERP, and nothing generates
allowances or overtime automatically.

### Allowance

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| AL-1 | Allowance categories with flags: duration (day/night/month/item), claimed, deduct_leave, other_absence, from Afyabox | ✅ | `from_afyabox` becomes a generic activity source setting; no Afyabox code in the ERP (see integrations) |
| AL-2 | Department allowance rates per department/section/position, rate per payroll period (HR and HOD screens) | 🟡 | Table exists, no controller or screen |
| AL-3 | Employee allowance (items × amount per item, start/end) with HOD → HR approval (workflow) | 🟡 | CRUD with a `status` field only. No HOD/HR approve actions. Move to workflow (#11) |
| AL-4 | **Daily/night allowance generated automatically** from roster + attendance, skipping leave days (`deduct_leave`) and absence/excuse-of-duty (`other_absence`), dedupe, recheck | ❌ | Needs R-1 (duties). With attendance off (Mode A), generate from the roster only, skipping approved leave |
| AL-5 | Theatre / activity-based allowance | ❌ | See P-6 and activity-based allowances |
| AL-6 | Night allowance | ❓ | HRMS schedule is commented out. Confirm with HR whether it's still paid |
| AL-7 | Allowance lines per payslip | ✅ | |
| AL-8 | HOD screens (daily allowance per department, manage/approve, assign allowance) and employee self view | 🟡 | Basic ERP lists only |

### Overtime

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| OT-1 | **Overtime rates per department + position** (`department_overtimes`, hourly amount) | ❌ | No ERP table. `overtimes.amount_per_minute` has nothing to come from |
| OT-2 | **Overtime generated from attendance** (`overtime:generate`): overtime minutes against the rostered duty, at least 60 min, × hourly rate / 60. Records check-in/out and late minutes | ❌ | Needs R-1 + A-2. So it is Mode B only |
| OT-3 | Overtime request by employee (self-service) or entry by HOD/HR | ❌ | ERP `OvertimeController` is index only |
| OT-4 | Approval HOD → HOS (head of section) → HR (3 levels, workflow) | ❌ | ERP has HOD/HR columns only, no HOS level, no approve actions |
| OT-5 | Paid flag + overtime lines per payslip | 🟡 | ERP sets `is_paid`, but pays **all** approved unpaid overtime regardless of date. Should be limited to the payroll period, with older items shown for HR to confirm |
| OT-6 | Max overtime hours per duty | ❌ | `employee_timetables.max_overtime_hours` (R-1) |
| OT-7 | Reports: department overtime list + PDF, HR department overtime, payroll overtime report, employee self view | ❌ | |

### Design note: overtime and allowances in both payroll modes
Automatic overtime and daily allowances in HRMS depend on attendance. So the two payroll modes need two paths:
- **Mode B (attendance):** overtime generated from attendance against the roster (OT-2), daily allowances
  from roster + attendance (AL-4). Approvers check them before payroll.
- **Mode A (no attendance):** overtime comes from **requests/entries approved through the workflow** (OT-3, OT-4)
  at the configured rate (OT-1). Daily allowances come from **rostered duties** minus approved leave.
- Both paths produce the same approved overtime/allowance records, so the payroll engine doesn't care which was used.

### Data migration notes (Allowance + Overtime)
- `overtimes`: the ERP lacks `hos_approved`, `hos_comment`, `is_hos_approved`, `amount_per_duration`,
  `attendance_checkin`, `attendance_checkout`, `punch_in`, `punch_in_late_minutes`, `punch_out_late_minutes`,
  `company_branche_id`, `created_by`, `updated_by`. `isPaid` → `is_paid`.
- `department_overtimes`: **no ERP table** (OT-1). Needed before any overtime can be priced.
- `allowance_categories.from_afyabox` → activity source configuration. `emp_allowances` gains `allowance_category_id` in the ERP.
- Migrate only **unpaid** approved overtime/allowances as open items. Paid ones are history linked to payslips.

**Allowance + Overtime verdict:** tables ready, process missing. For the parallel payroll run in Mode A,
at least **OT-1 (rates), OT-3 (requests), OT-4 (approval), AL-2 (allowance rates), AL-3 (allowance approval)**
are needed, plus OT-5 limited to the payroll period. Automatic generation (OT-2, AL-4) follows the attendance engine.

## Module pass: Loans (checked 2026-11-09)

**What already matches:** the tables. ERP `loan_providers`, `employee_loans`, `loan_histories`,
`saccos_members`, `saccos_contributions`, `heslbs` and `employee_monthly_advances` match HRMS (plus `company_id`;
the advances table is also spelt correctly and has `month`/`year`). The payroll engine deducts loan instalments
(`min(instalment, remain)`), writes loan history, closes paid-off loans and records SACCOS contributions.
Loan provider and advance screens exist.

| # | Feature (HRMS) | ERP | Notes |
|---|---|---|---|
| LN-1 | Assign loan to employee (amount, instalment, provider, date, employee approved) and update it | ❌ | `LoanController` has create/store/edit/update methods, but **only `index` is routed**, so loans can't be added from the UI |
| LN-2 | Loan instalment deducted in payroll + loan history per payslip | ✅/❓ | **Check regeneration:** the engine adds the instalment to `returned` each time it runs. Regenerating the same sheet would probably count it twice. Loan balances should only change when the payroll is approved/closed (the same lock idea as work entries) |
| LN-3 | Loan providers (incl. SACCOS as provider) | ✅ | |
| LN-4 | SACCOS members: add member, monthly amount, from/to date, total contribution | 🟡 | Tables exist. No member screen (P-15). ERP lacks `to_date`, `total_amount` |
| LN-5 | SACCOS contribution list + history, SACCOS loans list | ❌ | Reports (P-9 style) |
| LN-6 | HESLB members + report | 🟡 | Table exists. Screen and report under P-9 / P-15 |
| LN-7 | Salary advances: HR records advances, employee advance request page | 🟡 | ERP has advance CRUD. **Neither system deducts advances in payroll.** Confirm with HR how advances are recovered today (maybe as a loan or other deduction) |
| LN-8 | Employee self view of loans and deductions (`single/employee/deduction`) | ❌ | |

### Data migration notes (Loans)
- `employee_loans`: `employeeApproved` has no ERP column. `isActive` → `is_active`. **Migrate `returned` / `remain` exactly** (open balances).
- `loan_histories`: `out_standing_loan` → `outstanding_loan`.
- `saccos_members`: ERP lacks `to_date`, `total_amount`. `saccos_contributions`: ERP lacks `total_contribution`.
- `employee_mothly_advances` → `employee_monthly_advances` (fill `month`/`year` from `date_advanced`).

**Loans verdict:** close to ready. Before the parallel run: **LN-1 (route the loan screens)** and
**LN-2 (balances change only on approval, not on every regeneration)**. Then SACCOS member screens and reports.

## Design requirement: work entries (payroll, allowances and overtime never read attendance directly)

**Problem:** in HRMS, attendance is read directly by payroll (attendance deduction), daily allowances
(`deduct_leave`/`other_absence` check attendance statuses) and overtime (minutes from `attendance_transas`).
A client without BioTime or any device therefore can't use these features properly. Adding an
"if attendance" switch in each place would scatter the logic.

**Approach:** put one layer between time data and money: **work entries**. Established systems do the
same thing. Odoo generates *work entries* from either the contract's working schedule or attendance.
SAP distinguishes *negative time recording* (assume the schedule was worked, record only exceptions) from
*positive time recording* (record actual times).

### 1. Work entries: one row per employee per day
| Field | Meaning |
|---|---|
| `employee_id`, `date` | |
| `type` | worked · paid leave · unpaid leave · absent · excused (ED/admitted) · public holiday · off day |
| `planned_hours`, `worked_hours`, `overtime_minutes`, `late_minutes` | |
| `duty_id` | the rostered duty/shift, if any |
| `source` | **schedule** · **attendance** · **leave** · **manual** · **claim** · **external** |
| `locked` | set when a payroll uses it, so later changes don't silently change paid salary |

### 2. How work entries are produced (the configurable part)
**Time source** setting: company default → override per contract type / pay group → override per employee
(HRMS's `payrollUseAttendancy` + `hasAttendance` already follow this pattern).

| Time source | What creates the "worked" days | Needs device |
|---|---|---|
| **Schedule** (negative recording, default) | Planned days from the roster, or from a **default working schedule** when there is no roster (e.g. Mon–Fri 8h, set per company or contract type). Assumed worked | No |
| **Attendance** (positive recording) | Punches matched to the planned duty (A-2). No punches → flagged, never silently absent | Yes (any `AttendanceSource`) |

In **both** sources the same overrides apply on top:
- **approved leave** → paid/unpaid leave entry
- **public holidays** → holiday entry
- **HR manual corrections** and **approved attendance claims**
- **excuse-of-duty / admitted** → excused entry

If the attendance integration is switched off, everyone falls back to **Schedule** automatically.

### 3. Everything else reads only work entries

| Consumer | Rule (same in both time sources) |
|---|---|
| **Basic pay / pro-rata** | Paid days = days in period − unpaid leave − absent days, with joiners/leavers pro-rated (P-1) |
| **Unpaid leave deduction** | Count of unpaid leave entries (P-3) |
| **Attendance deduction** | Only from `absent` / `late_minutes` entries. In Schedule mode these exist only if HR records them |
| **Daily / night allowance** | Count of worked entries on day/night duties. `deduct_leave` skips leave entries, `other_absence` skips absent/excused entries (AL-4) |
| **Overtime** | **Overtime source** setting: *approved requests* (default, works without devices), *attendance* (`overtime_minutes` ≥ threshold), or *attendance + approval*. Priced with the department/position rate (OT-1) |
| **Activity-based allowances** | Unchanged: count from the activity source (see integrations), may also require a worked entry that day |

### 4. Per-allowance configuration
Each allowance category gets a **basis**: fixed per period · per worked day/shift · per activity count ·
per approved claim. Plus the existing flags `deduct_leave` and `other_absence`, which now act on work entries.
The same allowance definition then works for a client with BioTime, with another device, or with none.

### 5. HR control
- A **work entries calendar** per employee/department: see the month, correct a day, see the source of each entry.
- Before payroll: a **check screen** listing flagged days (missing punches, conflicts between leave and attendance), to resolve or accept.
- Payroll locks the entries it used. Later corrections become adjustments in the next payroll, not silent changes.

### 6. Effect on the gap list
- Payroll Mode A/B = time source **Schedule** / **Attendance**.
- P-1, P-2, P-3, AL-4 and OT-2 are all built on work entries instead of reading `attendance_transas` directly.
- **New item W-1: work entries table + generator + calendar/check screen. ERP status ❌.** It's the base for the
  parallel payroll run in Schedule mode. A default working schedule makes it usable even before rosters (R-1) are migrated.

## Design requirement: configurable integrations

The ERP must work for any company. Some will have BioTime, some another device, some none.
Some will have Afyabox, some another hospital or operations system, some none. **No external
system name is built into the core.** Each one is an optional adapter a company switches on and configures.

### HRMS today
- **BioTime (attendance):** turned on/off only through `.env` (`BIOTIME_ENABLED`). Connection details in
  `config/database.php`, queried directly in 73 places across controllers.
- **Afyabox (allowance details):** `AfyaboxController` calls two hardcoded report URLs
  (`http://192.168.1.245/afyabox/.../surgery_numberOfTests_report_search.php` and the procedure one).
  It adds up `Total` for about 400 surgery/procedure item IDs that are **hardcoded in the code**, giving
  a daily "number of cases". `AllowanceController` then pays **department allowance amount × number of cases**
  (theatre allowance). There is already a manual "number of cases" form, so the fallback concept exists.
  Afyabox is also read for excuse-of-duty and admitted staff (attendance side), through fixed DB connections.

### ERP design

**1. Activity-based allowances (generic, no Afyabox in the core).** An allowance type is calculated as one of:
- fixed amount per period
- amount per day / per shift worked
- **amount per activity count**: rate × number of activities (cases, procedures, deliveries, trips…)

The activity count comes from an **Activity Source** chosen per allowance.

**2. Activity Source adapters,** configured per company in an Integrations screen:

| Adapter | For whom | Settings |
|---|---|---|
| Manual entry (default) | Every company, always available | HOD/HR enters counts; goes through approval |
| CSV / Excel upload | Any company with any system that can export a report | Column mapping (date, item code, count, optional employee/department) |
| Generic REST API | Any system with an HTTP endpoint | URL, auth (none/token/basic), date parameter, JSON path to the count |
| Database query | Systems reachable by DB (read-only user) | Connection (encrypted), saved SQL returning the count for a date |

There is **no `AfyaboxController` or any system-specific code** in the ERP. Kairuki is just one saved
REST configuration (URL + token + JSON path) pointing at Afyabox.

**3. The ERP asks only for the number it needs.** For theatre allowance that is "total cases on date X".
Preferred contract the external system exposes (the ERP publishes it as a small spec):

```
GET {configured_url}?date=2026-11-09        (optional: &department=THEATRE)
→ 200 {"date": "2026-11-09", "count": 37}
```

Which surgeries/procedures count is the **external system's** business logic, not the ERP's. So the
~400 hardcoded item IDs in HRMS move to Afyabox (one endpoint returning the total), not into the ERP.
If a system can't be changed and only returns a raw report, the REST adapter's JSON path / a sum over
a list is the fallback, still configuration only.

**4. Who gets it:** department/section (as HRMS does now) or individual employees, with the rate taken
from the department allowance. If the source gives per-employee data, map employees with a configurable
key (employee number, email, or an external ID field on the employee).

**5. Safety:**
- every fetched count is stored with its source and the raw response
- counts go through the normal allowance approval before payroll
- if the source is down or switched off, the day is flagged and HR can enter the count manually; it is never silently zero

### Same rules for attendance (BioTime and others)
- `AttendanceSource` adapters: BioTime, ZKTeco direct, CSV import, manual
- per-company on/off, connection settings (encrypted, admins only), **Test connection** button, last sync time and errors
- employee ↔ device code mapping in the UI (HRMS uses the employee id)
- queued sync only while switched on, with a sync log; keeps the outage-safe backfill HRMS got on 2026-08-31
- when off: payroll Mode A + manual attendance and claims
- excuse-of-duty and admitted-staff feeds follow the same pattern: optional source, with a manual fallback

ERP status: ❌. No activity-based allowances, no adapters, no integrations screen (see #1, P-2, P-6, P-8).

## Next passes

1. Compare module by module the features both had before April, with the same table format.
   Done: **Payroll**, **Employee + Contract**, **Attendance + Roster**, **Leave**, **Allowance + Overtime**, **Loans**. All core HR modules compared.
2. ~~Write the HRMS → ERP data migration mapping~~ Done: see `hrms-migration-mapping.md` (2026-11-09).
3. Before switching over: run payroll in both systems for 1–2 months and compare every line.

## Added to the plan (2026-11-11)

- **Planning module**: strategic plan (multi-year) and annual plan per financial year with activities, objectives,
  service output, target and KPI. See architecture.md §17.
- **Performance appraisal**: new design (HOD activities → employee sub-activities; employees record progress entries
  describing what they did, each with a percentage capped by a configurable maximum per entry, submitted for approval and approved by
  their supervisor / head of department; optional quarterly
  minimum percentage; leave-aware), replacing the unused HRMS appraisal. To be discussed before
  building. See architecture.md §17.
- **Employee numbers without configuration**: fall back to the HRMS numbering (ST/PT/VOL by contract type, interns
  and residents with their own series). Done 2026-11-11.

