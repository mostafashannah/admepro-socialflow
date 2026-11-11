#!/bin/bash
# Daily SocialFlow backup: database dump + uploaded files, kept for 14 days.
# Runs on the server only (the web server refuses to serve .sh files).
#
#   One-time setup (as root):
#     bash /var/www/socialflow/backup-db.sh --install
#   Run a backup now:
#     bash /var/www/socialflow/backup-db.sh
#   Restore the database from a backup:
#     gunzip -c /var/backups/socialflow/db-YYYY-MM-DD_HHMM.sql.gz | mysql -u root -p socialflow
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="${SF_BACKUP_DIR:-/var/backups/socialflow}"
KEEP_DAYS="${SF_BACKUP_KEEP_DAYS:-14}"

if [ "${1:-}" = "--install" ]; then
  mkdir -p "$DEST"; chmod 700 "$DEST"
  CRON="/etc/cron.d/socialflow-backup"
  echo "# SocialFlow daily backup at 03:17 server time" > "$CRON"
  echo "17 3 * * * root bash $APP_DIR/backup-db.sh >> /var/log/socialflow-backup.log 2>&1" >> "$CRON"
  chmod 644 "$CRON"
  echo "Installed: daily backup at 03:17 into $DEST (log: /var/log/socialflow-backup.log)"
  echo "Running the first backup now..."
  exec bash "$APP_DIR/backup-db.sh"
fi

mkdir -p "$DEST"; chmod 700 "$DEST"
STAMP="$(date +%F_%H%M)"

# Read DB credentials from config.php without printing them.
read_const() { php -r "define('SF_CLI_READ',1); @include '$APP_DIR/config.php'; echo defined('$1') ? constant('$1') : '';"; }
DB_HOST="$(read_const DB_HOST)"; DB_NAME="$(read_const DB_NAME)"; DB_USER="$(read_const DB_USER)"; DB_PASS="$(read_const DB_PASS)"
[ -n "$DB_NAME" ] || { echo "$(date) ERROR: could not read DB settings from config.php"; exit 1; }

# Password goes through a private temp file, never the command line.
CNF="$(mktemp)"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf "[client]\nhost=%s\nuser=%s\npassword=%s\n" "${DB_HOST:-127.0.0.1}" "$DB_USER" "$DB_PASS" > "$CNF"

DB_FILE="$DEST/db-$STAMP.sql.gz"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB_NAME" | gzip -9 > "$DB_FILE.part"
mv "$DB_FILE.part" "$DB_FILE"; chmod 600 "$DB_FILE"

# Uploaded files (STORAGE_ROOT from config.php).
STORE="$(read_const STORAGE_ROOT)"
if [ -n "$STORE" ] && [ -d "$STORE" ]; then
  tar -czf "$DEST/files-$STAMP.tar.gz.part" -C "$(dirname "$STORE")" "$(basename "$STORE")" && mv "$DEST/files-$STAMP.tar.gz.part" "$DEST/files-$STAMP.tar.gz"
  chmod 600 "$DEST/files-$STAMP.tar.gz"
fi

# Keep the last N days.
find "$DEST" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete
find "$DEST" -maxdepth 1 -name '*.part' -mmin +120 -delete

echo "$(date) OK: $(du -h "$DB_FILE" | cut -f1) database backup -> $DB_FILE"
