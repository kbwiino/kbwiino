#!/usr/bin/env bash
# One-command server setup for ResearchMentor AI on Ubuntu 20.04/22.04/24.04: a fresh VPS, or a server that already
# runs other sites (it reuses an existing MySQL/MariaDB, PHP-FPM >= 8.2 and Apache or nginx).
#
# Run as root from a checkout of the repository (the app is the researchmentor/ folder of the repo):
#   git clone https://github.com/kbwiino/kbwiino.git /var/www/researchmentor-repo
#   sudo ADMIN_EMAIL=you@example.org /var/www/researchmentor-repo/researchmentor/deploy/scripts/provision.sh
#
# It performs every step of docs/DEPLOYMENT.md: packages, system user, database accounts (random passwords), .env,
# Composer, migrations, first institution + System Administrator, PHP-FPM pool, Apache or nginx site, HTTPS
# certificate, background worker, cron, logrotate, backup configuration and a final health check.
# Before changing anything it checks which web server owns ports 80/443 and that it can log in to the database
# server as root (socket authentication or /root/.my.cnf). It never disables other sites, PHP-FPM pools or global
# PHP settings.
#
# Variables (all optional except ADMIN_EMAIL):
#   ADMIN_EMAIL          e-mail of the first System Administrator; also used for Let's Encrypt notices
#   DOMAIN               default mentor.versalynx.net (its DNS A/AAAA record must point at this server)
#   INSTITUTION_NAME     default "Demo University";  INSTITUTION_CODE default DEMO;  INSTITUTION_COUNTRY default UG
#   ADMIN_FIRST / ADMIN_LAST   default "System" / "Administrator"
#   ANTHROPIC_API_KEY    Claude API key (asked for, hidden, when run interactively); without it the AI features
#                        stay unavailable in production (no mock)
#   TLS                  letsencrypt (default) | self-signed (testing only) | existing (certificate already in place)
#   WEB_SERVER           apache | nginx (default: whichever already listens on ports 80/443; Apache on a fresh server)
#   PHP_VER              default: the newest installed php-fpm >= 8.2, otherwise installed
#   DB_NAME              default researchmentor
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
port_owner() {   # port_owner <port> -> name of the process listening on it, or empty
  ss -ltnpH "( sport = :$1 )" 2>/dev/null | grep -o 'users:(("[^"]*' | head -n1 | sed 's/users:(("//' || true
}
db_installed() { [[ -x /usr/sbin/mysqld || -x /usr/sbin/mariadbd ]] || command -v mysqld >/dev/null || command -v mariadbd >/dev/null; }
db_start() { svc start mysql >/dev/null 2>&1 || svc start mariadb >/dev/null 2>&1 || true; }
db_ready() { mysql -N -B -e 'SELECT 1' >/dev/null 2>&1; }
installed_fpm() { ls /usr/sbin/php-fpm* 2>/dev/null | sed 's#.*php-fpm##' | sort -V | tail -n1; }
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
      -e "s#/usr/bin/php #$PHP_BIN #g" \
      -e "s#mentor\.versalynx\.net#$DOMAIN#g" -e "s#admin@versalynx\.net#$ADMIN_EMAIL#g" "$1" > "$2"
}

# ---------------------------------------------------------------------------------------------------------------
log "0/12 Pre-flight checks (nothing is changed yet)"
. /etc/os-release 2>/dev/null || true
echo "System: ${PRETTY_NAME:-unknown}"
OWNER80="$(port_owner 80)"; OWNER443="$(port_owner 443)"
if [[ -z "${WEB_SERVER:-}" ]]; then
  case "${OWNER443:-$OWNER80}" in
    nginx) WEB_SERVER=nginx ;;
    apache2|httpd) WEB_SERVER=apache ;;
    "") WEB_SERVER=apache ;;
    *) die "ports 80/443 are used by '${OWNER443:-$OWNER80}', which this script cannot configure. Nothing was changed." ;;
  esac
fi
[[ "$WEB_SERVER" =~ ^(apache|nginx)$ ]] || die "WEB_SERVER must be apache or nginx."
for o in "$OWNER80" "$OWNER443"; do
  [[ -z "$o" ]] && continue
  [[ "$WEB_SERVER" == nginx && "$o" == nginx ]] && continue
  [[ "$WEB_SERVER" == apache && "$o" =~ ^(apache2|httpd)$ ]] && continue
  die "ports 80/443 are used by '$o', not by $WEB_SERVER (port 80: ${OWNER80:-free}, port 443: ${OWNER443:-free}). Nothing was changed."
done
echo "Web server: $WEB_SERVER (port 80: ${OWNER80:-free}, port 443: ${OWNER443:-free})"

NGINX_MUX_FILE=""; NGINX_LISTEN=""; MUX_TARGET=""
if [[ "$WEB_SERVER" == nginx ]] && grep -rqs 'ssl_preread' /etc/nginx/; then
  # nginx multiplexes 443 by SNI (e.g. Jitsi Meet with TURN on 443): route our name to the internal TLS port of the web sites
  NGINX_MUX_FILE="$(grep -rls --exclude='*.bak*' 'map \$ssl_preread_server_name' /etc/nginx/ | head -n1 || true)"
  [[ -n "$NGINX_MUX_FILE" ]] || die "nginx uses ssl_preread on port 443 but no 'map \$ssl_preread_server_name' was found. Nothing was changed."
  MUX_TARGET="$(awk '/map \$ssl_preread_server_name/{f=1;next} f&&/}/{exit} f&&NF==2{gsub(";","",$2); print $2}' "$NGINX_MUX_FILE" | grep -vi turn | head -n1 || true)"
  MUX_PORT="$(grep -A4 "upstream $MUX_TARGET\b" "$NGINX_MUX_FILE" | grep -oE ':[0-9]+;' | head -n1 | tr -d ':;' || true)"
  [[ -n "$MUX_TARGET" && -n "$MUX_PORT" ]] || die "could not read the web upstream from $NGINX_MUX_FILE. Nothing was changed."
  # reuse the exact listen lines of the existing sites on that port (nginx needs identical address/options)
  NGINX_LISTEN="$(grep -RhE "^[[:space:]]*listen[[:space:]]+([^;]*:)?$MUX_PORT[[:space:];]" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null \
                  | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*default_server//' -e 's/[[:space:]]*#.*//' | sort -u || true)"
  [[ -n "$NGINX_LISTEN" ]] || die "no nginx site listens on port $MUX_PORT, the target of '$MUX_TARGET' in $NGINX_MUX_FILE. Nothing was changed."
  echo "nginx multiplexes port 443 by SNI ($NGINX_MUX_FILE): $DOMAIN will use upstream '$MUX_TARGET' (port $MUX_PORT)"
fi

if db_installed; then
  db_start
  for i in $(seq 1 20); do db_ready && break; sleep 1; done
  db_ready || die "a database server is installed, but 'mysql' cannot log in as root. Start it, or put root credentials in /root/.my.cnf ([client] user=root password=...), then re-run. Nothing was changed."
  echo "Database server: $(mysql -N -B -e 'SELECT VERSION()') (existing; it will be reused)"
else
  echo "Database server: none installed (MySQL will be installed)"
fi

# ---------------------------------------------------------------------------------------------------------------
log "1/12 Packages"
if [[ -z "${PHP_VER:-}" && -n "$(installed_fpm)" ]] && dpkg --compare-versions "$(installed_fpm)" ge 8.2; then
  PHP_VER="$(installed_fpm)"   # reuse the newest PHP-FPM already on the server
fi
if [[ "${SKIP_APT:-0}" != 1 ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -y -q software-properties-common ca-certificates curl git unzip openssl cron logrotate iproute2
  CANDIDATE="$(apt-cache policy php-fpm 2>/dev/null | sed -n 's/.*Candidate: [0-9]*:\{0,1\}\([0-9]\+\.[0-9]\+\).*/\1/p')"
  if [[ -z "${PHP_VER:-}" ]] && dpkg --compare-versions "${CANDIDATE:-0}" lt 8.2; then
    log "Ubuntu ships PHP ${CANDIDATE:-?}; adding the ondrej/php PPA for PHP 8.3"
    add-apt-repository -y ppa:ondrej/php && apt-get update -q
    PHP_VER=8.3
  fi
  PHP_VER="${PHP_VER:-$CANDIDATE}"
  PKGS=("php$PHP_VER-fpm" "php$PHP_VER-cli" "php$PHP_VER-mysql" "php$PHP_VER-mbstring" "php$PHP_VER-xml"
        "php$PHP_VER-zip" "php$PHP_VER-curl" "php$PHP_VER-intl" "php$PHP_VER-opcache")
  if [[ "$WEB_SERVER" == apache ]]; then PKGS+=(apache2); elif ! command -v nginx >/dev/null; then PKGS+=(nginx); fi
  command -v certbot >/dev/null || PKGS+=(certbot)
  apt-get install -y -q "${PKGS[@]}"
  if ! db_installed; then
    apt-get install -y -q mysql-server || { warn "mysql-server could not be installed; installing MariaDB instead"; apt-get install -y -q mariadb-server; }
  fi
fi
[[ -n "${PHP_VER:-}" ]] || PHP_VER="$(installed_fpm)"
[[ -n "$PHP_VER" && -x "/usr/sbin/php-fpm$PHP_VER" ]] || die "php-fpm not found; install PHP 8.2+ FPM or unset SKIP_APT."
dpkg --compare-versions "$PHP_VER" ge 8.2 || die "PHP $PHP_VER is too old; 8.2 or newer is required."
PHP_BIN="$(command -v "php$PHP_VER" || true)"
[[ -n "$PHP_BIN" ]] || die "php$PHP_VER (CLI) not found."
# Composer 2 (Ubuntu 20.04 ships Composer 1)
COMPOSER_BIN="$(command -v composer || true)"
COMPOSER_MAJOR="$( [[ -n "$COMPOSER_BIN" ]] && "$PHP_BIN" "$COMPOSER_BIN" --version 2>/dev/null | grep -oE 'version [0-9]+' | grep -oE '[0-9]+' || echo 0)"
if [[ "${COMPOSER_MAJOR:-0}" -lt 2 ]]; then
  echo "installing Composer 2 in /usr/local/bin"
  TMPC="$(mktemp -d)"
  curl -fsSL https://getcomposer.org/installer -o "$TMPC/composer-setup.php"
  [[ "$(curl -fsSL https://composer.github.io/installer.sig)" == "$("$PHP_BIN" -r "echo hash_file('sha384', '$TMPC/composer-setup.php');")" ]] \
    || die "Composer installer checksum mismatch."
  "$PHP_BIN" "$TMPC/composer-setup.php" --install-dir=/usr/local/bin --filename=composer --quiet
  rm -rf "$TMPC"; COMPOSER_BIN=/usr/local/bin/composer
fi
echo "App: $APP_DIR   PHP: $PHP_VER   Web: $WEB_SERVER   Domain: $DOMAIN   TLS: $TLS"

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
log "4/12 Database"
db_start
for i in $(seq 1 30); do db_ready && break; sleep 1; done
db_ready || die "cannot log in to the database server as root (see /root/.my.cnf)."
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
sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" "$PHP_BIN" "$COMPOSER_BIN" install -d "$APP_DIR" \
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
POOL="/etc/php/$PHP_VER/fpm/pool.d/researchmentor.conf"
install_tpl "$APP_DIR/deploy/php/researchmentor-pool.conf" "$POOL"
# The settings of deploy/php/99-researchmentor.ini are applied to this pool only, so other sites on the server keep
# their own PHP settings. (OPcache memory sizes and expose_php are server-wide and are left as they are.)
{ echo; echo "; from deploy/php/99-researchmentor.ini (pool-scoped)"
  sed -e 's/;.*//' -e 's/[[:space:]]*$//' "$APP_DIR/deploy/php/99-researchmentor.ini" | grep '=' \
    | grep -vE '^(expose_php|opcache\.enable|opcache\.memory_consumption|opcache\.max_accelerated_files)[[:space:]]*=' \
    | sed -E 's/^([^ =]+)[[:space:]]*=[[:space:]]*(.*)$/php_admin_value[\1] = \2/'
} >> "$POOL"
rm -f "/etc/php/$PHP_VER/fpm/conf.d/99-researchmentor.ini"   # server-wide copy written by earlier versions of this script
"/usr/sbin/php-fpm$PHP_VER" -t
svc restart "php$PHP_VER-fpm"

# ---------------------------------------------------------------------------------------------------------------
log "9/12 $WEB_SERVER and HTTPS ($TLS)"
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"; KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
web_reload() {
  if [[ "$WEB_SERVER" == apache ]]; then
    apache2ctl configtest && { svc reload apache2 2>/dev/null || svc restart apache2; }
  else
    nginx -t && { svc reload nginx 2>/dev/null || svc restart nginx; }
  fi
}
if [[ "$WEB_SERVER" == apache ]]; then
  a2enmod -q rewrite headers ssl http2 proxy proxy_fcgi setenvif expires alias >/dev/null
  SITE="/etc/apache2/sites-available/$DOMAIN.conf"
else
  SITE="/etc/nginx/sites-available/$DOMAIN.conf"
  if [[ -n "$NGINX_MUX_FILE" ]] && ! grep -qE "^[[:space:]]*$DOMAIN[[:space:]]" "$NGINX_MUX_FILE"; then
    MUX_BAK="/var/backups/researchmentor-nginx/$(basename "$NGINX_MUX_FILE").$(date +%Y%m%d%H%M%S)"
    install -d -m 700 "$(dirname "$MUX_BAK")"; cp -a "$NGINX_MUX_FILE" "$MUX_BAK"
    D="$DOMAIN" T="$MUX_TARGET" awk '{print} /map \$ssl_preread_server_name/ {print "        " ENVIRON["D"] " " ENVIRON["T"] ";"}' \
      "$MUX_BAK" > "$NGINX_MUX_FILE"
    if ! nginx -t 2>/dev/null; then
      cp -a "$MUX_BAK" "$NGINX_MUX_FILE"
      die "could not add $DOMAIN to the nginx SNI map in $NGINX_MUX_FILE; the file was restored."
    fi
    echo "added $DOMAIN to the SNI map in $NGINX_MUX_FILE (backup: $MUX_BAK)"
  fi
fi
case "$TLS" in
  letsencrypt)
    if [[ ! -f "$CERT" ]]; then
      # temporary HTTP-only site that serves nothing but the ACME challenge directory
      if [[ "$WEB_SERVER" == apache ]]; then
        printf '<VirtualHost *:80>\n    ServerName %s\n    DocumentRoot %s\n    <Directory %s>\n        Require all granted\n    </Directory>\n</VirtualHost>\n' \
          "$DOMAIN" "$ACME_ROOT" "$ACME_ROOT" > "/etc/apache2/sites-available/$DOMAIN-acme.conf"
        a2dissite -q "$DOMAIN" >/dev/null 2>&1 || true
        a2ensite -q "$DOMAIN-acme" >/dev/null
      else
        printf 'server {\n    listen 80;\n%s    server_name %s;\n    root %s;\n}\n' \
          "$( [[ -f /proc/net/if_inet6 ]] && echo '    listen [::]:80;' )" "$DOMAIN" "$ACME_ROOT" > "/etc/nginx/sites-enabled/$DOMAIN-acme.conf"
        rm -f "/etc/nginx/sites-enabled/$DOMAIN.conf"
      fi
      web_reload
      certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" --agree-tos -m "$ADMIN_EMAIL" -n
      if [[ "$WEB_SERVER" == apache ]]; then
        a2dissite -q "$DOMAIN-acme" >/dev/null; rm -f "/etc/apache2/sites-available/$DOMAIN-acme.conf"
      else
        rm -f "/etc/nginx/sites-enabled/$DOMAIN-acme.conf"
      fi
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
if [[ "$WEB_SERVER" == apache ]]; then
  install_tpl "$APP_DIR/deploy/apache/mentor.versalynx.net.conf" "$SITE"
else
  install_tpl "$APP_DIR/deploy/nginx/mentor.versalynx.net.conf" "$SITE"
  if [[ -n "$NGINX_LISTEN" ]]; then   # SNI multiplexing: listen where the other web sites listen instead of on 443
    L="$NGINX_LISTEN" awk '/^[[:space:]]*listen[[:space:]]+(\[::\]:)?443 ssl/ {
        if (!done) { n = split(ENVIRON["L"], a, "\n"); for (i = 1; i <= n; i++) print "    " a[i]; done = 1 }
        next } { print }' "$SITE" > "$SITE.tmp"
    mv "$SITE.tmp" "$SITE"
  fi
fi
sed -i -e "s#/etc/letsencrypt/live/$DOMAIN/fullchain.pem#$CERT#" -e "s#/etc/letsencrypt/live/$DOMAIN/privkey.pem#$KEY#" "$SITE"
if [[ "$WEB_SERVER" == nginx && ! -f /proc/net/if_inet6 ]]; then sed -i '/listen[[:space:]]*\[::\]/d' "$SITE"; fi   # host without IPv6
if [[ "$WEB_SERVER" == apache ]]; then a2ensite -q "$DOMAIN" >/dev/null; else ln -sf "$SITE" "/etc/nginx/sites-enabled/$DOMAIN.conf"; fi
web_reload
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; echo "ufw: ports 80 and 443 allowed"
fi

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
for i in $(seq 1 15); do   # wait until the reloaded web server answers with ResearchMentor's own login page
  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "https://$DOMAIN/login" || true)"
  [[ "$code" == 200 ]] && "${CURL[@]}" "https://$DOMAIN/login" 2>/dev/null | grep -q 'name="csrf-token"' && break
  code="$code (not the ResearchMentor login page)"; sleep 2
done
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
  * if the site is not reachable from outside: open ports 80 and 443 in your hosting provider's firewall
  * ANTHROPIC_API_KEY in $ENVF (then: sudo systemctl restart researchmentor-worker php$PHP_VER-fpm), and a monthly spend limit
  * privacy notice (docs/PRIVACY_NOTICE_TEMPLATE.md) in Admin > Institution settings
  * GPG_RECIPIENT and an off-site copy in /etc/researchmentor-backup.env; rehearse a restore
  * updates: cd $REPO_ROOT && sudo -u $APP_USER git pull --ff-only && sudo $APP_DIR/deploy/scripts/deploy.sh
EOF
