# Test server from scratch, with production data

For a fresh Ubuntu server (24.04 or 26.04). The test server runs the ERP and imports a **copy** of the production HRMS
database. The steps are run as a user with `sudo`. Replace the values in `<angle brackets>`.

**Safety first.** The test server holds real staff data. Until step 11 is done:
- it must not send SMS or email to real staff;
- it must not write to the real fingerprint (BioTime) database;
- the scheduler (cron) stays off.

## 1. Server packages

**PHP repository.** Ubuntu 24.04 gets PHP 8.3 from Ubuntu itself or the `ppa:ondrej/php` PPA. Ubuntu 26.04
(resolute) has no PPA build; use Ondřej Surý's repository at packages.sury.org. Download the key from
`apt.gpg` (not the sury.org home page, which saves an HTML file that apt rejects with `NO_PUBKEY`):

```bash
sudo curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
file /usr/share/keyrings/sury-php.gpg   # must say "OpenPGP Public Key"
echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | sudo tee /etc/apt/sources.list.d/sury-php.list
sudo apt update
apt policy php8.3                        # shows a candidate from packages.sury.org
```

If `apt update` still shows `NO_PUBKEY`, the `signed-by=` path does not match the key file. Remove any
leftover `ppa:ondrej/php` entry on 26.04 (`sudo rm -f /etc/apt/sources.list.d/ondrej-*php*`), it gives a 404.

On a fish shell, write `; and` where the commands below use `&&`, and run SQL only at the `mysql>` prompt.

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Africa/Dar_es_Salaam
sudo apt -y install nginx mysql-server supervisor git unzip curl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd php8.3-readline
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
php -v && composer -V
```

Raise PHP limits for the import and uploads. Edit `/etc/php/8.3/fpm/php.ini`: set `memory_limit = 512M`,
`upload_max_filesize = 20M`, `post_max_size = 25M`, `max_execution_time = 120`. Then run:

```bash
sudo systemctl restart php8.3-fpm
```

## 2. Databases

```bash
sudo mysql_secure_installation
sudo mysql
```

```sql
CREATE DATABASE smart_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE hrms_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'erp'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL ON smart_erp.* TO 'erp'@'localhost';
GRANT ALL ON hrms_prod.* TO 'erp'@'localhost';
FLUSH PRIVILEGES;
```

Copy the production HRMS dump to the server, then restore it into `hrms_prod`. Never restore into the live HRMS
server.

```bash
scp <dump.sql> <user>@<test-server>:/tmp/      # from your machine
mysql -u erp -p hrms_prod < /tmp/<dump.sql>    # on the server
```

## 3. Code

The repositories are private. Give the server a deploy key:

1. Run `ssh-keygen -t ed25519 -C erp-test` on the server.
2. Add `~/.ssh/id_ed25519.pub` to both GitHub repositories (Settings → Deploy keys, read only).
3. Clone:

```bash
sudo mkdir -p /var/www/erp && sudo chown $USER:www-data /var/www/erp
cd /var/www/erp
git clone git@github.com:mukhusin/smart_erp_backend.git backend
git clone git@github.com:mukhusin/smart_erp_frontend.git frontend-src
```

## 4. Backend

```bash
cd /var/www/erp/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_NAME="Smart ERP"
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Dar_es_Salaam
APP_URL=http://<test-server-name-or-ip>
FRONTEND_URL=http://<test-server-name-or-ip>
SANCTUM_STATEFUL_DOMAINS=<test-server-name-or-ip>
SESSION_DOMAIN=null
SESSION_IDLE_MINUTES=20

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smart_erp
DB_USERNAME=erp
DB_PASSWORD=<strong password>

HRMS_DB_DATABASE=hrms_prod

QUEUE_CONNECTION=database
LOG_STACK=daily
LOG_DAILY_DAYS=7

# Test server: emails are written to the log, never sent
MAIL_MAILER=log
```

Then create the tables and the base data. Run only the two base seeders, not the demo seeder.

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=ErpModuleSeeder --force
php artisan erp:sync-permissions --roles --prune
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan config:cache && php artisan route:cache
```

Create the platform admin (the system owner, who registers companies):

```bash
php artisan tinker --execute='App\Models\User::create(["username"=>"admin","email"=>"<your email>","password"=>Hash::make("<strong password>"),"is_platform_admin"=>true,"is_active"=>true,"email_verified_at"=>now()]);'
```

## 5. Frontend

Build it on your own machine, which already has Node 26, then upload it. Run in `smart_erp_frontend`:

```bash
git pull && npm ci && npx ng build
rsync -av --delete dist/smart-erp-frontend/browser/ <user>@<test-server>:/var/www/erp/frontend/
```

To build on the server instead, install Node 26 with nvm, then run the same `npm ci && npx ng build` in
`frontend-src` and copy `dist/smart-erp-frontend/browser/` to `/var/www/erp/frontend/`.

## 6. Web server (one site: the app at `/`, the API at `/api`)

Create `/etc/nginx/sites-available/erp` with:

```nginx
server {
    listen 80;
    server_name <test-server-name-or-ip>;
    client_max_body_size 25M;

    root /var/www/erp/frontend;
    index index.html;

    location = /index.html { add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0"; }
    location / { try_files $uri $uri/ /index.html; }

    location ~ ^/(api|sanctum|storage)(/|$) {
        root /var/www/erp/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        root /var/www/erp/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/erp/backend/public/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }
}
```

Then enable the site and reload:

```bash
sudo ln -s /etc/nginx/sites-available/erp /etc/nginx/sites-enabled/erp
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Open `http://<test-server>/login` and log in as the platform admin.

## 7. Queue worker (payroll, staff messages, attendance)

Create `/etc/supervisor/conf.d/erp-worker.conf` with:

```ini
[program:erp-worker]
command=php /var/www/erp/backend/artisan queue:work --queue=default,attendance --timeout=1800 --tries=1 --memory=512
user=www-data
autostart=true
autorestart=true
stopwaitsecs=1810
stdout_logfile=/var/www/erp/backend/storage/logs/worker.log
redirect_stderr=true
```

Then load it:

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

## 8. Register the company

As platform admin, open **Platform → Companies → Register**:
- **Organisation:** name and short name.
- **Login link:** `kairuki`.
- **Branches:** the branches.
- **Modules:** choose the modules to use.
- **First administrator:** the details of the company's first administrator.

Then set the branding (logo, colours, contact details) on the company.

The first administrator only manages users, roles, workflows and settings. They see no HR or finance data
unless they are also given an HR or finance role.

## 9. Import production HRMS data

Run a dry run first. Every check must say OK.

```bash
cd /var/www/erp/backend
sudo -u www-data php -d memory_limit=2G artisan erp:import-hrms kairuki --dry-run
```

Then run it for real. Copy the HRMS staff files (photos, documents) to the server first if you want them:
`rsync -av <prod>:/path/to/hrms/storage/app/public/ /tmp/hrms-files/`.

```bash
sudo -u www-data php -d memory_limit=2G artisan erp:import-hrms kairuki --files=/tmp/hrms-files
```

The import took about 12 minutes on the production copy. It is safe to run again: it updates and never duplicates,
so later you can restore a newer dump into `hrms_prod` and re-run it.

## 10. Make the test server safe (before the scheduler)

Log in as an HR administrator imported from HRMS and do the following.

**SMS:** open Administration → SMS settings, set the driver to **Log** (messages are written to the log, not sent),
and save.

**Attendance (fingerprints):** open Attendance → Settings and choose one of these:
- turn attendance **off** for the test;
- point it at a **copy** of the BioTime database;
- keep it pointing at production BioTime, but only with a user that has SELECT rights.

The ERP writes staff to BioTime at night (`attendance:push-staff`). That must never reach the production devices
from a test server.

**Email:** `MAIL_MAILER=log` is already set, so invitations and notices go to `storage/logs` instead of staff
inboxes. Do not send invitations to real users from the test server.

**Theatre allowance:** the AfyaBox report address (Allowance → Categories) only reads data, so it is safe.

## 11. Scheduler

Only after step 10, add this cron entry:

```bash
sudo crontab -u www-data -e
# add this line:
* * * * * php /var/www/erp/backend/artisan schedule:run >> /dev/null 2>&1
```

## 12. Set up what the import cannot know

Follow the "Before" list in `deployment.md` (Switch-over from HRMS). Check that each role has the right users,
then set up:
- approval chains;
- contract templates;
- retirement age;
- the payroll settings.

For finance, in this order:
1. Chart of accounts.
2. Fiscal years.
3. Bank and cash accounts.
4. Accounting framework.
5. Opening balances.
6. Payroll posting accounts.
7. Procurement settings (clearing, retention and damages accounts) and procurement methods.
8. Store settings.
9. Asset settings and the accounts on each category.

Enable the Audit module if you want to try the auditor roles.

## 13. Updating the test server later

On the server:

```bash
cd /var/www/erp/backend && git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan erp:sync-permissions --roles
php artisan config:cache && php artisan route:cache
sudo supervisorctl restart erp-worker
```

On your machine, for the frontend:

```bash
git pull && npm ci && npx ng build
rsync -av --delete dist/smart-erp-frontend/browser/ <user>@<test-server>:/var/www/erp/frontend/
```

Read the newest sections of `deployment.md` for extra steps a release needs.

## Checks

- **Login:** `http://<test-server>/login?org=kairuki` shows the company login page.
- **Platform admin:** `/platform` lists the company.
- **Data:** employees, payroll sheets and payslips match HRMS (the import report lists the counts).
- **Queue worker:** create a draft payroll for a test month. It shows "Working out…" and then the bell
  notifies "Payroll ready". If it stays "Working out…", check `sudo supervisorctl status`.
- **Logs:** `storage/logs/laravel-*.log` holds no errors, and SMS and emails appear there instead of being sent.
