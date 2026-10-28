#!/usr/bin/env bash
# One-command server setup for ResearchMentor AI on a fresh Ubuntu 22.04/24.04 VPS.
#
# Run as root from a checkout of the repository (the app is the researchmentor/ folder of the repo):
#   git clone https://github.com/kbwiino/kbwiino.git /var/www/researchmentor-repo
#   sudo ADMIN_EMAIL=you@example.org /var/www/researchmentor-repo/researchmentor/deploy/scripts/provision.sh
#
# It performs every step of docs/DEPLOYMENT.md: packages, system user, MySQL accounts (random passwords), .env,
# Composer, migrations, first institution + System Administrator, PHP-FPM pool, Apache vhost, HTTPS certificate,
# background worker, cron, logrotate, backup configuration and a final health check.
#
# Variables (all optional except ADMIN_EMAIL):
#   ADMIN_EMAIL          e-mail of the first System Administrator; also used for Let's Encrypt notices
#   DOMAIN               default mentor.versalynx.net (its DNS A/AAAA record must point at this server)
#   INSTITUTION_NAME     default "Demo University";  INSTITUTION_CODE default DEMO;  INSTITUTION_COUNTRY default UG
#   ADMIN_FIRST / ADMIN_LAST   default "System" / "Administrator"
#   ANTHROPIC_API_KEY    Claude API key (asked for, hidden, when run interactively); without it the AI features
#                        stay unavailable in production (no mock)
#   TLS                  letsencrypt (default) | self-signed (testing only) | existing (certificate already in place)
#   PHP_VER              default: the installed php-fpm version (8.2 or newer is required)
#   SKIP_APT=1           do not install packages;  SKIP_DNS_CHECK=1  do not check that DOMAIN points here
#
# Safe to re-run: existing passwords, .env values, users and certificates are kept.
set -euo pipefail

log()  { printf '\n== %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root (sudo)."

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ -f "$APP_DIR/public/index.php" && -f "$APP_DIR/bin/migrate.php" ]] || die "cannot find the application next to this script ($APP_DIR)."
REPO_ROOT="$(git -c safe.directory="*" -C "$APP_DIR" rev-parse --show-toplevel 2>/dev/null || echo "$APP_DIR")"

DOMAIN="${DOMAIN:-mentor.versalynx.net}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
INSTITUTION_NAME="${INSTITUTION_NAME:-Demo University}"
INSTITUTION_CODE="${INSTITUTION_CODE:-DEMO}"
INSTITUTION_COUNTRY="${INSTITUTION_COUNTRY:-UG}"
ADMIN_FIRST="${ADMIN_FIRST:-System}"
ADMIN_LAST="${ADMIN_LAST:-Administrator}"
TLS="${TLS:-letsencrypt}"
APP_USER=rmapp
DATA_DIR=/var/lib/researchmentor
ACME_ROOT=/var/www/letsencrypt
DB_NAME="${DB_NAME:-researchmentor}"
CREDENTIALS_FILE=/root/researchmentor-first-login.txt

[[ -n "$ADMIN_EMAIL" ]] || die "set ADMIN_EMAIL, e.g. sudo ADMIN_EMAIL=you@example.org $0"
[[ "$TLS" =~ ^(letsencrypt|self-signed|existing)$ ]] || die "TLS must be letsencrypt, self-signed or existing."

have_systemd() { [[ -d /run/systemd/system ]]; }
svc() {   # svc <action> <service>
  if have_systemd; then systemctl "$1" "$2"; else service "$2" "$1"; fi
}
randpw() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32; }
env_get() {   # env_get <file> <KEY>  -> value or empty
  [[ -f "$1" ]] || return 0
  sed -n "s/^$2=\([^#]*\).*/\1/p" "$1" | tail -n1 | sed 's/[[:space:]]*$//'
}
env_set() {   # env_set <file> <KEY> <VALUE>  (replaces the line or appends it)
  local f="$1" k="$2" v="$3"
  if grep -q "^$k=" "$f"; then
    K="$k" V="$v" awk 'BEGIN{k=ENVIRON["K"]; v=ENVIRON["V"]} index($0, k"=")==1 {print k"="v; next} {print}' "$f" > "$f.tmp"
    cat "$f.tmp" > "$f"; rm -f "$f.tmp"
  else
    printf '%s=%s\n' "$k" "$v" >> "$f"
  fi
}
install_tpl() {   # install_tpl <source> <destination>: copy a deploy/ file, adapting paths, PHP version and domain
  sed -e "s#/var/www/researchmentor#$APP_DIR#g" \
      -e "s#/etc/php/8\.3#/etc/php/$PHP_VER#g" -e "s#php8\.3#php$PHP_VER#g" -e "s#php-fpm8\.3#php-fpm$PHP_VER#g" \
      -e "s#mentor\.versalynx\.net#$DOMAIN#g" -e "s#admin@versalynx\.net#$ADMIN_EMAIL#g" "$1" > "$2"
}

# ---------------------------------------------------------------------------------------------------------------
log "1/12 Packages"
if [[ "${SKIP_APT:-0}" != 1 ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -y -q software-properties-common ca-certificates curl git unzip openssl cron logrotate
  CANDIDATE="$(apt-cache policy php-fpm 2>/dev/null | sed -n 's/.*Candidate: [0-9]*:\{0,1\}\([0-9]\+\.[0-9]\+\).*/\1/p')"
  if [[ -z "${PHP_VER:-}" ]] && dpkg --compare-versions "${CANDIDATE:-0}" lt 8.2; then
    log "Ubuntu ships PHP ${CANDIDATE:-?}; adding the ondrej/php PPA for PHP 8.3"
    add-apt-repository -y ppa:ondrej/php && apt-get update -q
    PHP_VER=8.3
  fi
  PHP_VER="${PHP_VER:-$CANDIDATE}"
  apt-get install -y -q apache2 mysql-server "php$PHP_VER-fpm" "php$PHP_VER-cli" "php$PHP_VER-mysql" "php$PHP_VER-mbstring" \
    "php$PHP_VER-xml" "php$PHP_VER-zip" "php$PHP_VER-curl" "php$PHP_VER-intl" "php$PHP_VER-opcache" composer certbot
fi
if [[ -z "${PHP_VER:-}" ]]; then
  PHP_VER="$(ls /usr/sbin/php-fpm* 2>/dev/null | sed 's#.*php-fpm##' | sort -V | tail -n1)"
fi
[[ -n "$PHP_VER" && -x "/usr/sbin/php-fpm$PHP_VER" ]] || die "php-fpm not found; install PHP 8.2+ FPM or unset SKIP_APT."
dpkg --compare-versions "$PHP_VER" ge 8.2 || die "PHP $PHP_VER is too old; 8.2 or newer is required."
PHP_BIN="$(command -v "php$PHP_VER" || command -v php)"
echo "App: $APP_DIR   PHP: $PHP_VER   Domain: $DOMAIN   TLS: $TLS"

# ---------------------------------------------------------------------------------------------------------------
log "2/12 DNS"
if [[ "$TLS" == letsencrypt && "${SKIP_DNS_CHECK:-0}" != 1 ]]; then
  RESOLVED="$(getent ahosts "$DOMAIN" | awk '{print $1}' | sort -u | tr '\n' ' ')"
  MINE="$(hostname -I 2>/dev/null) $(curl -4 -s -m 5 https://api.ipify.org || true) $(curl -6 -s -m 5 https://api64.ipify.org || true)"
  [[ -n "$RESOLVED" ]] || die "$DOMAIN does not resolve. Create its DNS A/AAAA record first (or set SKIP_DNS_CHECK=1)."
  match=0; for ip in $RESOLVED; do [[ " $MINE " == *" $ip "* ]] && match=1; done
  [[ $match == 1 ]] || die "$DOMAIN resolves to $RESOLVED, but this server is $MINE. Fix the DNS record (or set SKIP_DNS_CHECK=1)."
  echo "$DOMAIN -> $RESOLVED (this server)"
else
  echo "skipped"
fi

# ---------------------------------------------------------------------------------------------------------------
log "3/12 System user and directories"
id "$APP_USER" >/dev/null 2>&1 || useradd -r -m -d "$DATA_DIR" -s /usr/sbin/nologin "$APP_USER"
install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$DATA_DIR" "$DATA_DIR"/{private,exports,tmp,sessions,.composer}
install -d -m 755 "$ACME_ROOT"
chown -R "$APP_USER:$APP_USER" "$REPO_ROOT"
install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$APP_DIR/storage" "$APP_DIR/storage/logs" "$APP_DIR/storage/cache"

# ---------------------------------------------------------------------------------------------------------------
log "4/12 MySQL"
svc start mysql >/dev/null 2>&1 || true
for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
mysqladmin ping >/dev/null 2>&1 || die "MySQL is not running."
[[ -f "$APP_DIR/.env" ]] || { cp "$APP_DIR/.env.example" "$APP_DIR/.env"; }
RUNTIME_PW="$(env_get "$APP_DIR/.env" DB_PASSWORD)"
[[ -z "$RUNTIME_PW" || "$RUNTIME_PW" == change-me ]] && RUNTIME_PW="$(randpw)"
MIGRATOR_PW="$(env_get /etc/researchmentor-migrate.env DB_PASSWORD)"; MIGRATOR_PW="${MIGRATOR_PW:-$(randpw)}"
BACKUP_PW="$(env_get /etc/researchmentor-backup.env DB_PASS)"; BACKUP_PW="${BACKUP_PW:-$(randpw)}"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'researchmentor'@'localhost' IDENTIFIED BY '$RUNTIME_PW';
ALTER USER 'researchmentor'@'localhost' IDENTIFIED BY '$RUNTIME_PW';
GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO 'researchmentor'@'localhost';
CREATE USER IF NOT EXISTS 'rm_migrator'@'localhost' IDENTIFIED BY '$MIGRATOR_PW';
ALTER USER 'rm_migrator'@'localhost' IDENTIFIED BY '$MIGRATOR_PW';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO 'rm_migrator'@'localhost';
CREATE USER IF NOT EXISTS 'rm_backup'@'localhost' IDENTIFIED BY '$BACKUP_PW';
ALTER USER 'rm_backup'@'localhost' IDENTIFIED BY '$BACKUP_PW';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON $DB_NAME.* TO 'rm_backup'@'localhost';
GRANT PROCESS ON *.* TO 'rm_backup'@'localhost';
FLUSH PRIVILEGES;
SQL
umask 077
printf 'DB_USERNAME=rm_migrator\nDB_PASSWORD=%s\n' "$MIGRATOR_PW" > /etc/researchmentor-migrate.env
if [[ ! -f /etc/researchmentor-backup.env ]]; then
  cat > /etc/researchmentor-backup.env <<EOF
DB_NAME=$DB_NAME
DB_USER=rm_backup
DB_PASS=$BACKUP_PW
APP_DIR=$APP_DIR
STORAGE_DIR=$DATA_DIR/private
BACKUP_DIR=/var/backups/researchmentor
# GPG_RECIPIENT=backup@example.org   # strongly recommended before copying backups off the server
KEEP_DAYS=14
EOF
fi
umask 022
echo "database $DB_NAME; accounts researchmentor (DML), rm_migrator (DDL), rm_backup (dump)"

# ---------------------------------------------------------------------------------------------------------------
log "5/12 .env"
ENVF="$APP_DIR/.env"
env_set "$ENVF" APP_ENV production
env_set "$ENVF" APP_DEBUG false
env_set "$ENVF" APP_URL "https://$DOMAIN"
env_set "$ENVF" DB_HOST 127.0.0.1
env_set "$ENVF" DB_PORT 3306
env_set "$ENVF" DB_DATABASE "$DB_NAME"
env_set "$ENVF" DB_USERNAME researchmentor
env_set "$ENVF" DB_PASSWORD "$RUNTIME_PW"
env_set "$ENVF" PRIVATE_STORAGE_PATH "$DATA_DIR/private"
env_set "$ENVF" EXPORT_STORAGE_PATH "$DATA_DIR/exports"
env_set "$ENVF" SESSION_SECURE_COOKIE true
env_set "$ENVF" QUEUE_DRIVER database
env_set "$ENVF" AI_PROVIDER auto
if [[ -z "${ANTHROPIC_API_KEY:-}" && -z "$(env_get "$ENVF" ANTHROPIC_API_KEY)" && -t 0 ]]; then
  read -rsp "Anthropic API key (input hidden; press Enter to add it later): " ANTHROPIC_API_KEY; echo
fi
if [[ -n "${ANTHROPIC_API_KEY:-}" ]]; then env_set "$ENVF" ANTHROPIC_API_KEY "$ANTHROPIC_API_KEY"; fi
chown root:"$APP_USER" "$ENVF"; chmod 640 "$ENVF"
[[ -n "$(env_get "$ENVF" ANTHROPIC_API_KEY)" ]] && echo "Anthropic API key: set" || warn "ANTHROPIC_API_KEY is empty: AI features will be unavailable until you add it to $ENVF."

# ---------------------------------------------------------------------------------------------------------------
log "6/12 Composer"
sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" composer install -d "$APP_DIR" \
  --no-dev --optimize-autoloader --no-interaction --no-progress

# ---------------------------------------------------------------------------------------------------------------
log "7/12 Migrations and first administrator"
( set -a; source /etc/researchmentor-migrate.env; set +a
  cd "$APP_DIR" && sudo -E -u "$APP_USER" "$PHP_BIN" bin/migrate.php up )
( cd "$APP_DIR" && sudo -u "$APP_USER" "$PHP_BIN" bin/console.php prompts:sync )
USERS="$(mysql -N -B -e "SELECT COUNT(*) FROM $DB_NAME.users")"
if [[ "$USERS" == 0 ]]; then
  if [[ "$(mysql -N -B -e "SELECT COUNT(*) FROM $DB_NAME.institutions WHERE code='${INSTITUTION_CODE^^}'")" == 0 ]]; then
    ( cd "$APP_DIR" && sudo -u "$APP_USER" "$PHP_BIN" bin/console.php institution:create "$INSTITUTION_NAME" "$INSTITUTION_CODE" "$INSTITUTION_COUNTRY" )
  fi
  ADMIN_PW="$(randpw | cut -c1-16)7a"
  ( cd "$APP_DIR" && sudo -u "$APP_USER" env NEW_USER_PASSWORD="$ADMIN_PW" "$PHP_BIN" bin/console.php user:create \
      "$ADMIN_EMAIL" "$ADMIN_FIRST" "$ADMIN_LAST" sysadmin "${INSTITUTION_CODE^^}" )
  umask 077
  printf 'ResearchMentor AI first login\nURL:      https://%s/login\nEmail:    %s\nPassword: %s\nChange this password after signing in, then delete this file.\n' \
    "$DOMAIN" "$ADMIN_EMAIL" "$ADMIN_PW" > "$CREDENTIALS_FILE"
  umask 022
  echo "System Administrator $ADMIN_EMAIL created; password saved in $CREDENTIALS_FILE"
else
  echo "$USERS user(s) already exist; no account created."
fi

# ---------------------------------------------------------------------------------------------------------------
log "8/12 PHP-FPM"
install_tpl "$APP_DIR/deploy/php/researchmentor-pool.conf" "/etc/php/$PHP_VER/fpm/pool.d/researchmentor.conf"
install_tpl "$APP_DIR/deploy/php/99-researchmentor.ini" "/etc/php/$PHP_VER/fpm/conf.d/99-researchmentor.ini"
[[ -f "/etc/php/$PHP_VER/fpm/pool.d/www.conf" ]] && mv "/etc/php/$PHP_VER/fpm/pool.d/www.conf" "/etc/php/$PHP_VER/fpm/pool.d/www.conf.disabled"
"/usr/sbin/php-fpm$PHP_VER" -t
svc restart "php$PHP_VER-fpm"

# ---------------------------------------------------------------------------------------------------------------
log "9/12 Apache and HTTPS ($TLS)"
a2enmod -q rewrite headers ssl http2 proxy proxy_fcgi setenvif expires alias >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
VHOST="/etc/apache2/sites-available/$DOMAIN.conf"
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"; KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
case "$TLS" in
  letsencrypt)
    if [[ ! -f "$CERT" ]]; then
      # temporary HTTP-only vhost that serves nothing but the ACME challenge directory
      cat > "/etc/apache2/sites-available/$DOMAIN-acme.conf" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $ACME_ROOT
    <Directory $ACME_ROOT>
        Require all granted
    </Directory>
</VirtualHost>
EOF
      a2dissite -q "$DOMAIN" >/dev/null 2>&1 || true
      a2ensite -q "$DOMAIN-acme" >/dev/null && apache2ctl configtest && svc reload apache2
      certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" --agree-tos -m "$ADMIN_EMAIL" -n
      a2dissite -q "$DOMAIN-acme" >/dev/null; rm -f "/etc/apache2/sites-available/$DOMAIN-acme.conf"
    fi ;;
  self-signed)
    CERT=/etc/ssl/researchmentor/fullchain.pem; KEY=/etc/ssl/researchmentor/privkey.pem
    if [[ ! -f "$CERT" ]]; then
      install -d -m 700 /etc/ssl/researchmentor
      openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" \
        -keyout "$KEY" -out "$CERT" 2>/dev/null
    fi
    warn "self-signed certificate: browsers will show a warning. Use only for testing." ;;
  existing)
    [[ -f "$CERT" && -f "$KEY" ]] || die "TLS=existing but $CERT / $KEY not found." ;;
esac
install_tpl "$APP_DIR/deploy/apache/mentor.versalynx.net.conf" "$VHOST"
sed -i -e "s#/etc/letsencrypt/live/$DOMAIN/fullchain.pem#$CERT#" -e "s#/etc/letsencrypt/live/$DOMAIN/privkey.pem#$KEY#" "$VHOST"
a2ensite -q "$DOMAIN" >/dev/null
apache2ctl configtest
svc reload apache2 2>/dev/null || svc restart apache2
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then ufw allow 'Apache Full' >/dev/null; echo "ufw: Apache Full allowed"; fi

# ---------------------------------------------------------------------------------------------------------------
log "10/12 Background worker, cron and logrotate"
install_tpl "$APP_DIR/deploy/cron/researchmentor" /etc/cron.d/researchmentor
install_tpl "$APP_DIR/deploy/logrotate/researchmentor" /etc/logrotate.d/researchmentor
chmod 644 /etc/cron.d/researchmentor /etc/logrotate.d/researchmentor
if have_systemd; then
  install_tpl "$APP_DIR/deploy/systemd/researchmentor-worker.service" /etc/systemd/system/researchmentor-worker.service
  systemctl daemon-reload
  systemctl enable --now researchmentor-worker >/dev/null
  systemctl restart researchmentor-worker
  systemctl is-active --quiet researchmentor-worker && echo "worker: running (systemd)"
else
  # no systemd (e.g. a container): drain the queue every minute from cron instead
  sed -i 's/^# \(\* \* \* \* \* \)/\1/' /etc/cron.d/researchmentor
  warn "systemd not available: the queue is processed by the per-minute cron fallback."
fi

# ---------------------------------------------------------------------------------------------------------------
log "11/12 Health check"
( cd "$APP_DIR" && sudo -u "$APP_USER" "$PHP_BIN" bin/console.php check ) || warn "some checks failed (see above; 'ai' fails until an API key is set)."

# ---------------------------------------------------------------------------------------------------------------
log "12/12 Web checks"
CURL=(curl -s -m 15 --noproxy '*' --resolve "$DOMAIN:443:127.0.0.1" --resolve "$DOMAIN:80:127.0.0.1")
[[ "$TLS" == self-signed ]] && CURL+=(-k)
code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "https://$DOMAIN/login" || true)"
echo "https://$DOMAIN/login -> $code"; [[ "$code" == 200 ]] || warn "login page did not return 200."
{ "${CURL[@]}" -I "https://$DOMAIN/login" || true; } | grep -iE '^(strict-transport-security|content-security-policy|x-frame-options):' || warn "security headers missing."
echo "http redirect -> $("${CURL[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "http://$DOMAIN/login" || true)"
for p in /.env /composer.json /vendor/autoload.php /storage/logs/ /app/Support/Config.php /index.php.bak; do
  c="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "https://$DOMAIN$p" || true)"
  [[ "$c" == 403 || "$c" == 404 ]] && echo "blocked $p ($c)" || warn "$p returned $c (should be 403/404)"
done

cat <<EOF

ResearchMentor AI is configured at https://$DOMAIN
$( [[ -f "$CREDENTIALS_FILE" ]] && echo "First login details: $CREDENTIALS_FILE (root only)" )
Remaining items for you (docs/DEPLOYMENT.md, section 12):
  * ANTHROPIC_API_KEY in $ENVF (then: sudo systemctl restart researchmentor-worker php$PHP_VER-fpm), and a monthly spend limit
  * privacy notice (docs/PRIVACY_NOTICE_TEMPLATE.md) in Admin > Institution settings
  * GPG_RECIPIENT and an off-site copy in /etc/researchmentor-backup.env; rehearse a restore
  * updates: cd $REPO_ROOT && sudo -u $APP_USER git pull --ff-only && sudo $APP_DIR/deploy/scripts/deploy.sh
EOF
