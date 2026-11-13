# Deployment notes

Steps to run on the server when a release needs more than pulling the code. Newest release first.

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
