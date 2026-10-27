#!/usr/bin/env bash
# Deploy/update on the server. Run as root (or via sudo) from /var/www/researchmentor.
#   sudo -u rmapp git pull --ff-only
#   sudo ./deploy/scripts/deploy.sh
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/researchmentor}"
cd "$APP_DIR"
echo "== composer (no dev dependencies, optimized autoloader)"
sudo -u rmapp composer install --no-dev --optimize-autoloader --no-interaction
echo "== migrations (uses the migration DB account from /etc/researchmentor-migrate.env if present)"
if [[ -f /etc/researchmentor-migrate.env ]]; then set -a; source /etc/researchmentor-migrate.env; set +a; fi
sudo -E -u rmapp php bin/migrate.php up
sudo -u rmapp php bin/console.php prompts:sync || true
echo "== permissions"
chown -R rmapp:rmapp storage
chmod -R u=rwX,g=rX,o= storage
chmod 640 .env
echo "== reload PHP-FPM (clears OPcache) and restart the worker"
systemctl reload php8.3-fpm
systemctl restart researchmentor-worker || true
sudo -u rmapp php bin/console.php check
echo "Deploy finished."
