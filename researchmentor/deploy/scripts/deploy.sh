#!/usr/bin/env bash
# Deploy/update on the server (after the first install with deploy/scripts/provision.sh). Run as root:
#   cd <repository checkout> && sudo -u rmapp git pull --ff-only
#   sudo researchmentor/deploy/scripts/deploy.sh
set -euo pipefail
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP_VER="${PHP_VER:-$(ls /usr/sbin/php-fpm* 2>/dev/null | sed 's#.*php-fpm##' | sort -V | tail -n1)}"
svc() { if [[ -d /run/systemd/system ]]; then systemctl "$1" "$2"; else service "$2" "$1"; fi; }
cd "$APP_DIR"
echo "== composer (no dev dependencies, optimized autoloader)"
sudo -u rmapp -H env COMPOSER_HOME=/var/lib/researchmentor/.composer composer install --no-dev --optimize-autoloader --no-interaction
echo "== migrations (uses the migration DB account from /etc/researchmentor-migrate.env if present)"
if [[ -f /etc/researchmentor-migrate.env ]]; then set -a; source /etc/researchmentor-migrate.env; set +a; fi
sudo -E -u rmapp php bin/migrate.php up
sudo -u rmapp php bin/console.php prompts:sync || true
echo "== permissions"
chown -R rmapp:rmapp storage
chmod -R u=rwX,g=rX,o= storage
chmod 640 .env
echo "== reload PHP-FPM (clears OPcache) and restart the worker"
svc reload "php$PHP_VER-fpm"
svc restart researchmentor-worker 2>/dev/null || true
sudo -u rmapp php bin/console.php check || echo "WARNING: some health checks failed (the 'ai' check fails until ANTHROPIC_API_KEY is set)."
echo "Deploy finished."
