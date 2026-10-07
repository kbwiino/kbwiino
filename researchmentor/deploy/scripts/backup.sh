#!/usr/bin/env bash
# Nightly backup: MySQL dump + private file storage, optional GPG encryption, 14-day rotation.
# Configure /etc/researchmentor-backup.env (chmod 600), for example:
#   DB_NAME=researchmentor
#   DB_USER=rm_backup           # grant: SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER on researchmentor.*
#   DB_PASS=...                 # or use ~/.my.cnf
#   APP_DIR=/var/www/researchmentor
#   STORAGE_DIR=/var/lib/researchmentor/private
#   BACKUP_DIR=/var/backups/researchmentor
#   GPG_RECIPIENT=backup@versalynx.net   # optional: encrypt backups (strongly recommended for off-site copies)
#   KEEP_DAYS=14
set -euo pipefail
source /etc/researchmentor-backup.env
: "${DB_NAME:?}" "${DB_USER:?}" "${STORAGE_DIR:?}" "${BACKUP_DIR:?}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
umask 077
mkdir -p "$BACKUP_DIR"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

echo "[$(date -u +%FT%TZ)] dumping database"
MYSQL_PWD="${DB_PASS:-}" mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 -u "$DB_USER" "$DB_NAME" | gzip -9 > "$TMP/db-$STAMP.sql.gz"

echo "[$(date -u +%FT%TZ)] archiving private storage"
tar -C "$(dirname "$STORAGE_DIR")" -czf "$TMP/files-$STAMP.tar.gz" "$(basename "$STORAGE_DIR")"

for f in "$TMP"/*; do
  if [[ -n "${GPG_RECIPIENT:-}" ]]; then
    gpg --batch --yes --trust-model always -r "$GPG_RECIPIENT" -o "$BACKUP_DIR/$(basename "$f").gpg" -e "$f"
  else
    cp "$f" "$BACKUP_DIR/"
  fi
done
find "$BACKUP_DIR" -type f -mtime +"$KEEP_DAYS" -delete
echo "[$(date -u +%FT%TZ)] backup complete: $(ls -1 "$BACKUP_DIR" | wc -l) file(s) retained"
# Copy $BACKUP_DIR off the server (e.g. rclone/rsync to a second location). A backup on the same disk is not a backup.
