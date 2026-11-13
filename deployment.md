# Deployment notes

Steps to run on the server when a release needs more than pulling the code. Newest release first.

## Self-service profile (2026-11-13)

1. Run the migration. It adds `status`, `submitted_by` and `decision_comment` to the qualification, licence, experience,
   dependant and document tables. Existing records become `approved`.

   ```bash
   php artisan migrate
   ```

2. Companies registered before this release have no approval workflow for "Profile update by employee". Add one in
   Administration → Workflows (one HR step). Without it, records employees add are saved straight away.

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
