#!/usr/bin/env bash
# Restore a backup pair into the CURRENT server. DESTRUCTIVE: replaces the database and private files.
#   ./restore.sh /var/backups/researchmentor/db-<stamp>.sql.gz /var/backups/researchmentor/files-<stamp>.tar.gz
# For .gpg files decrypt first:  gpg -d file.gpg > file
set -euo pipefail
DB_SQL="${1:?db dump (.sql.gz)}"; FILES_TGZ="${2:?files archive (.tar.gz)}"
source /etc/researchmentor-backup.env
read -r -p "This will REPLACE database '$DB_NAME' and files in '$STORAGE_DIR'. Type RESTORE to continue: " ok
[[ "$ok" == "RESTORE" ]] || { echo aborted; exit 1; }
systemctl stop researchmentor-worker || true
gunzip -c "$DB_SQL" | MYSQL_PWD="${DB_PASS:-}" mysql -u "$DB_USER" "$DB_NAME"
tar -C "$(dirname "$STORAGE_DIR")" -xzf "$FILES_TGZ"
chown -R rmapp:rmapp "$STORAGE_DIR"
systemctl start researchmentor-worker || true
echo "Restore complete. Run: sudo -u rmapp php $APP_DIR/bin/console.php check"
