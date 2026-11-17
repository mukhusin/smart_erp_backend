# Deployment notes

Steps to run on the server when a release needs more than pulling the code. Newest release first.

## Switch-over from HRMS (runbook, rehearsed 2026-11-15)

Production dry run (15 Nov 2026 dump, restored to a separate database): 817 employees, 26 payrolls, 10,454 payslips,
599,897 attendance days, 821 users — 12 min 34 s, all 15 checks OK; 130 pending claims and 86 pending salary adjustments
imported as cancelled; 1 assistant HOD imported as deputy head.

Rehearsed on a new empty database with the local HRMS copy (data to Aug 2026: 776 employees, 9,203 payslips, 276,766 daily
allowances, 559,574 attendance days): full import **10 min 43 s**, peak memory ~400 MB, all 15 checks OK. A second run (the
switch-day catch-up) took **2 min 49 s**, inserted nothing and still matched. Use `php -d memory_limit=2G`.

Before (days ahead)
1. Restore a fresh dump of production HRMS into a separate database (never the live one) and point the `hrms` connection at it.
2. Register the company (platform admin) with its branches and modules; dry run, every check must say OK:
   `php -d memory_limit=2G artisan erp:import-hrms <slug> --dry-run`
3. Run it for real, with files: `... erp:import-hrms <slug> --files=/path/to/hrms/storage/app/public`.
4. Set up in the ERP what the import cannot know: roles' users check, approval chains (imported), theatre allowance source,
   contract templates, retirement age, SMS settings, BioTime write access, "View audit log" roles.
5. Run payroll in both systems for 1–2 months and compare every payslip (Payroll → sheet → Compare).

Switch day
1. Stop data entry in HRMS (announce a time). HR decides pending items in HRMS first: attendance claims and salary
   adjustments still pending are imported as cancelled (agreed 2026-11-15).
2. New dump → re-run the import (catch-up, ~3 min) → all checks OK.
3. Point users to the ERP; HRMS stays read-only for reference.

Import notes that are expected (not errors): duties with impossible dates (year 2925) in HRMS rosters, loan repayments
pointing to a payroll that no longer exists, annual leave plans never requested (kept as plans).

## Audit log, activity allowances, salary files (2026-11-15)

1. Run the migrations (`audit_logs`, `allowance_activity_counts`, two columns on `allowance_categories`):

   ```bash
   php artisan migrate
   ```

2. Re-run the import `pay` step so allowance categories get the right type (night = automatic, monthly = assigned,
   theatre = daily count):

   ```bash
   php artisan erp:import-hrms kairuki --only=pay
   ```

3. Allowance → Categories → Theatre Allowance: "The daily count is" → Fetched from another system, and enter the AfyaBox
   surgery report address, the query parameters (`l=0`, `Start_Date={date}`, `End_Date={date}`, `Report_Type=tests_done`,
   `Sponsor_ID=`), list `returnData.list_of_items`, field to add up `Total`, item id field `Item_ID` and the surgery item ids
   from HRMS. Use the plug button to test it. `allowance:generate` (23:30) fetches the last 3 days first.

4. Add `LOG_STACK=daily`, `LOG_DAILY_DAYS=7` and `SESSION_IDLE_MINUTES=20` (idle logout, as HRMS) to `.env` if not done yet.

   So the browser never keeps the app page after logout, serve the Angular `index.html` without caching (Apache, in the
   frontend site config):

   ```apache
   <Files "index.html">
       Header set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
   </Files>
   ```

5. Sending staff to the fingerprint devices writes to the BioTime database (`personnel_employee`, `personnel_department`,
   `personnel_position`, `personnel_employee_area`), so the BioTime user in Attendance → Settings needs INSERT/UPDATE on those
   tables (reading punches alone needed SELECT). It runs on the `attendance` queue (the worker already listens to it) and
   nightly at 01:30 (`attendance:push-staff`). Companies whose attendance is not BioTime are never touched.

6. Heads (2026-11-15): the migration adds "Duty roster" and "Duty roster change" approval (one step: head of department) to
   companies that have none. Re-run the import `roles` step (`--only=roles`) so the section-head role gets `roster.manage`
   (heads of section prepare their section's roster). Deputies are added in HR → Departments → Add deputy.

7. Give "View audit log" to the roles that should see Administration → Audit log and the employee History tab.

## Self-service profile (2026-11-13)

1. Run the migration. It adds `status`, `submitted_by` and `decision_comment` to the qualification, licence, experience,
   dependant and document tables. Existing records become `approved`.

   ```bash
   php artisan migrate
   ```

2. Companies registered before this release have no approval workflow for "Profile update by employee". Add one in
   Administration → Workflows (one HR step). Without it, records employees add are saved straight away.

3. The same for "Salary advance": new companies get Supervisor → HR; companies registered before need it added in
   Administration → Workflows. Without it, an advance applies as soon as it is entered.

## HRMS import, all phases (2026-11-13)

Migrations add `employee_patient_ids`, `uploaded_payrolls` and `uploaded_payroll_rows`, and widen
`employee_timetables.code` to 50 characters. Run `php artisan migrate`, then the import as below (all steps run by default).

## HRMS import, phase 1 (2026-11-13)

1. Run the migration. It adds the `legacy_ids` table (HRMS id → ERP id per table).

   ```bash
   php artisan migrate
   ```

2. Point the `hrms` connection at a **copy** of the production HRMS database, never the live one. In `.env`:
   `HRMS_DB_HOST`, `HRMS_DB_PORT`, `HRMS_DB_DATABASE`, `HRMS_DB_USERNAME`, `HRMS_DB_PASSWORD`
   (host, port, user and password fall back to the ERP's own `DB_*`).

3. Register the company first (platform admin), then dry-run and read the report. Every check must say OK.

   ```bash
   php artisan erp:import-hrms <company-slug> --dry-run --files=/path/to/hrms/storage/app/public
   ```

4. Run it for real (same command without `--dry-run`). It is safe to run again: rows copied before are updated, nothing is duplicated.

## Staff messages (2026-11-13)

1. Run the migration. It adds three columns to the `messagings` table (`filters`, `skipped_count`, `sent_at`).

   ```bash
   php artisan migrate
   ```

2. Sync permissions. This adds the new `hr.messages.send`, adds missing default permissions to every company's built-in roles (never removes any), and deletes the unused `admin.messaging.send`.

   ```bash
   php artisan erp:sync-permissions --roles --prune
   ```

3. Make sure the queue worker also processes the **default** queue, not only `attendance`. Messages are delivered by a queued job (`SendStaffMessageJob`) on the default queue. For example:

   ```bash
   php artisan queue:work --queue=default,attendance
   ```

4. Keep the scheduler cron running. `hr:send-messages` runs every minute to send scheduled messages.

   ```cron
   * * * * * php /path/to/smart_erp_backend/artisan schedule:run >> /dev/null 2>&1
   ```

## Imprest, petty cash, bank reconciliation and bank feeds (2026-11-17)

1. `php artisan migrate`: imprest columns and tables, bank reconciliation tables, and default approvals for every company
   ("Imprest retirement" and "Bank reconciliation": chief accountant) where none exist.
2. `php artisan erp:sync-permissions --roles` adds `accounting.reconcile` (accountants get it through `accounting.*`).
3. Payments → Settings: choose the staff advances account (an asset account) before staff ask for imprests.
4. The cron entry already runs `payments:imprest-reminders` daily at 07:00 and `accounting:bank-feeds` hourly.
5. Second migration (`2026_12_04_*`): imprest recovery, petty cash and bank feed tables; default approvals "Petty cash
   voucher" and "Petty cash top-up" (chief accountant). Run `php artisan erp:sync-permissions --roles` again for
   `payments.petty-cash` and give it to the petty cash custodians' role.
6. Salary recovery posts to the payroll posting "Other deductions payable" account (or net pay): set it in Accounting →
   Payroll posting. Bank feed tokens are encrypted with `APP_KEY`; keep the same key when moving the database.

## Payroll worked out in the background (2026-11-17)

New and "work out again" payrolls are calculated by a queued job (`GeneratePayrollJob`, default queue). After
`php artisan migrate` (adds `payroll_sheets.processing_started_at` and `processing_error`), make sure the queue worker
processes the default queue, for example `php artisan queue:work --queue=default,attendance --timeout=1800`. Without a
running worker, payrolls stay "Working out…" (after an hour they can be worked out again).

## Timezone Africa/Dar_es_Salaam (2026-11-17)

The application timezone is now `Africa/Dar_es_Salaam` (was UTC), the same as HRMS, so times imported from HRMS are
read correctly. Override with `APP_TIMEZONE` in `.env` only if a company is elsewhere. After pulling, run
`php artisan config:cache`. Times saved by the ERP before this change (it had no production data yet) were in UTC
and show 3 hours early; nothing needs converting on a fresh server.
