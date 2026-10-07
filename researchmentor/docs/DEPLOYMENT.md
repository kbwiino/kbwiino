# Deployment - Ubuntu VPS, Apache, PHP-FPM, MySQL, Certbot

Target: `https://mentor.versalynx.net` on Ubuntu 22.04/24.04. All configuration files referenced here live in `deploy/`.
These steps were exercised in a container with Apache 2.4.58 + PHP-FPM 8.3 + MySQL 8.0 (config syntax check, TLS/HTTP-2,
headers, private-path blocking, login/CSRF, upload validation, queue worker). Certbot issuance itself needs your real DNS.

## 1. Packages

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-intl \
                    composer certbot python3-certbot-apache git unzip
sudo a2enmod rewrite headers ssl http2 proxy proxy_fcgi setenvif expires
sudo systemctl disable --now php8.3-fpm 2>/dev/null; sudo systemctl enable --now php8.3-fpm
```

PHP extensions required: `pdo_mysql, curl, mbstring, fileinfo, zip, dom, xml, json, openssl` (and `pcntl` is optional for graceful worker shutdown). PHP >= 8.2.

## 2. System user and directories

```bash
sudo useradd -r -m -d /var/lib/researchmentor -s /usr/sbin/nologin rmapp
sudo install -d -o rmapp -g rmapp -m 750 /var/lib/researchmentor/{private,exports,tmp,sessions}
sudo install -d -o rmapp -g rmapp -m 755 /var/www/researchmentor
```

Private files (uploads, generated reports) live in `/var/lib/researchmentor/*` - **outside** the web root - under random 64-hex names.

## 3. MySQL

```bash
sudo mysql < deploy/mysql/setup.sql      # edit the two passwords in the file first
```

Creates the database (`utf8mb4`), a runtime account with DML only and a separate migration account with DDL.
Keep `innodb_ft_min_token_size` at the default (3). Enable binary logs/backups per your policy.

## 4. Application code

```bash
cd /var/www && sudo -u rmapp git clone <your-repo-url> researchmentor && cd researchmentor   # the app lives in the researchmentor/ folder of the repo
sudo -u rmapp composer install --no-dev --optimize-autoloader
sudo -u rmapp cp .env.example .env && sudo chown root:rmapp .env && sudo chmod 640 .env
```

Edit `.env` (never commit it). Production values:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mentor.versalynx.net
DB_HOST=127.0.0.1
DB_DATABASE=researchmentor
DB_USERNAME=researchmentor
DB_PASSWORD=<runtime password>
PRIVATE_STORAGE_PATH=/var/lib/researchmentor/private
EXPORT_STORAGE_PATH=/var/lib/researchmentor/exports
MAX_UPLOAD_MB=25
SESSION_SECURE_COOKIE=true
QUEUE_DRIVER=database            # AI reviews/extraction run in the worker, not in web requests
AI_ENABLED=true
AI_PROVIDER=auto                 # Claude when a key is present; the mock is refused in production
ANTHROPIC_API_KEY=<key>
ANTHROPIC_MODEL=claude-sonnet-5-5
ANTHROPIC_API_VERSION=2023-06-01
AI_MAX_TOKENS=8000
AI_TIMEOUT=120
```

Confirm the model name against Anthropic's current model list before go-live; it is configuration, not code.

## 5. Migrations and first data

```bash
# schema (use the migration account so the runtime account stays DML-only)
sudo -u rmapp env DB_USERNAME=rm_migrator DB_PASSWORD='<migrator password>' php bin/migrate.php up
sudo -u rmapp php bin/migrate.php status
sudo -u rmapp php bin/console.php prompts:sync

# first institution + first Graduate School administrator (password is read from the environment, not argv)
sudo -u rmapp php bin/console.php institution:create "Demo University" DEMO UG
sudo -u rmapp env NEW_USER_PASSWORD='<strong password>' php bin/console.php user:create gradadmin@yourdomain "First" "Last" grad_admin DEMO
sudo -u rmapp env NEW_USER_PASSWORD='<strong password>' php bin/console.php user:create sysadmin@yourdomain "First" "Last" sysadmin DEMO
```

`php bin/seed.php` (demo data) is **blocked** when `APP_ENV=production`. Create real faculties/departments/programmes in the database
(or extend the admin UI) and real users from the Users page.

## 6. PHP-FPM

```bash
sudo cp deploy/php/researchmentor-pool.conf /etc/php/8.3/fpm/pool.d/researchmentor.conf
sudo cp deploy/php/99-researchmentor.ini      /etc/php/8.3/fpm/conf.d/99-researchmentor.ini
sudo mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled   # unused default pool
sudo php-fpm8.3 -t && sudo systemctl restart php8.3-fpm
```

The pool runs as `rmapp`, restricts `open_basedir` to the app and `/var/lib/researchmentor`, disables shell functions and URL fopen, and logs to `storage/logs/php-error.log`.
Recommendations: size `pm.max_children` to RAM (~60-80 MB each); `opcache.validate_timestamps=0` means **reload FPM after each deploy** (`deploy/scripts/deploy.sh` does).
CLI settings for the worker can differ (`/etc/php/8.3/cli/conf.d/`): `memory_limit=512M`.

## 7. Apache + HTTPS (Certbot)

```bash
sudo cp deploy/apache/mentor.versalynx.net.conf /etc/apache2/sites-available/
# First get the certificate with a minimal HTTP vhost (point DNS A/AAAA records of mentor.versalynx.net at the server first):
sudo certbot certonly --apache -d mentor.versalynx.net --agree-tos -m admin@versalynx.net
sudo a2ensite mentor.versalynx.net && sudo a2dissite 000-default
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot renew --dry-run                       # renewal is handled by the certbot systemd timer
```

DocumentRoot is `/var/www/researchmentor/public` only. Verify afterwards:

```bash
curl -sI https://mentor.versalynx.net/login | grep -iE "strict-transport|content-security|x-frame"
for p in /.env /composer.json /vendor/autoload.php /storage/logs/ /app/Support/Config.php; do curl -s -o /dev/null -w "$p %{http_code}\n" https://mentor.versalynx.net$p; done   # all 403/404
```

## 8. Background worker and cron

```bash
sudo cp deploy/systemd/researchmentor-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now researchmentor-worker
sudo cp deploy/cron/researchmentor /etc/cron.d/researchmentor      # daily retention purge, log cleanup, backup (edit first)
```

If you cannot run systemd services, uncomment the per-minute `worker.php --once` cron line instead.
Failed jobs are retried (3 attempts, increasing delay) then marked `failed` in `background_jobs` (visible in the system health card).

## 9. Backups and restore

```bash
sudo install -m 600 /dev/null /etc/researchmentor-backup.env     # see the header of deploy/scripts/backup.sh for the variables
sudo /var/www/researchmentor/deploy/scripts/backup.sh            # DB dump + private files, optional GPG, 14-day rotation
sudo /var/www/researchmentor/deploy/scripts/restore.sh <db.sql.gz> <files.tar.gz>
```

Copy backups **off the server** (rclone/rsync). Encrypt anything leaving the host (`GPG_RECIPIENT`). Test a restore on a scratch VM at least once per term.

## 10. Logs

| What | Where | Rotation |
|---|---|---|
| Application events/errors (secrets redacted) | `storage/logs/app-YYYY-MM-DD.log` | cron deletes after 30 days |
| PHP errors | `storage/logs/php-error.log` | `deploy/logrotate/researchmentor` |
| Apache | `/var/log/apache2/mentor-*.log` | stock logrotate |
| Audit trail (who did what) | MySQL `audit_logs` | keep per your policy (suggest >= 2 years) |
| AI usage (tokens, latency, status; no content) | MySQL `ai_usage_logs` | keep for cost review |

## 11. Updating

```bash
cd /var/www/researchmentor && sudo -u rmapp git pull --ff-only && sudo ./deploy/scripts/deploy.sh
```

`deploy.sh` installs without dev dependencies, runs migrations, fixes permissions, reloads PHP-FPM, restarts the worker and runs `bin/console.php check`.

## 12. Go-live checklist

- [ ] `.env` is `root:rmapp 640`, `APP_DEBUG=false`, `APP_ENV=production`, strong DB passwords
- [ ] HTTPS only, HSTS present, certificate auto-renewal tested
- [ ] `/.env`, `/vendor/`, `/storage/`, `/app/` unreachable from the web
- [ ] Worker running; `php bin/console.php check` is green
- [ ] Backups run and a restore was rehearsed
- [ ] Privacy notice completed (`docs/PRIVACY_NOTICE_TEMPLATE.md`) and set in Admin > Institution settings; retention period configured
- [ ] Data-protection obligations reviewed with the institution's DPO/legal counsel (see `docs/SECURITY.md`)
- [ ] Anthropic key stored only in `.env`; monthly spend limit set in the Anthropic console
- [ ] A supervised pilot with real documents completed before wider rollout
