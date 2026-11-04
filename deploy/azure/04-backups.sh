#!/usr/bin/env bash
# GIMS — nightly backup of the database and uploaded DTR scans.
#   sudo bash deploy/azure/04-backups.sh --install   # install cron + credentials
#   sudo bash deploy/azure/04-backups.sh             # run one backup now
set -euo pipefail

BACKUP_DIR=/var/backups/gips
ENV_FILE=/var/www/gips/.env
CRED_FILE=/etc/gips/backup.cnf
STAMP="$(date +%F-%H%M%S)"
RETENTION_DAYS=14

env_value() {
  sudo grep -m1 -E "^$1=" "$ENV_FILE" | cut -d= -f2- | tr -d '\r' \
    | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

install_all() {
  echo "==> reading credentials from $ENV_FILE"
  local host user pass name ca
  host="$(env_value DB_HOST)"; user="$(env_value DB_USERNAME)"
  pass="$(env_value DB_PASSWORD)"; name="$(env_value DB_DATABASE)"
  ca="$(env_value MYSQL_ATTR_SSL_CA)"

  sudo mkdir -p /etc/gips "$BACKUP_DIR"
  sudo tee "$CRED_FILE" >/dev/null <<EOF
[client]
host=${host}
user=${user}
password=${pass}
ssl-ca=${ca}
EOF
  sudo chmod 600 "$CRED_FILE"

  sudo cp "$0" /usr/local/bin/gips-backup.sh
  sudo chmod 755 /usr/local/bin/gips-backup.sh
  sudo tee /etc/cron.d/gips-backup >/dev/null <<EOF
# GIMS nightly backup — 02:15 server time
15 2 * * * root /usr/local/bin/gips-backup.sh >> /var/log/gips-backup.log 2>&1
EOF
  echo "Installed /etc/cron.d/gips-backup (nightly 02:15) and $CRED_FILE"
  exit 0
}

if [ "${1:-}" = "--install" ]; then install_all; fi

echo "==> database dump"
sudo mkdir -p "$BACKUP_DIR"
sudo mysqldump --defaults-extra-file="$CRED_FILE" \
  --single-transaction --quick --no-tablespaces \
  "$(env_value DB_DATABASE)" | gzip | sudo tee "$BACKUP_DIR/db-$STAMP.sql.gz" >/dev/null

echo "==> uploaded files"
sudo tar -czf "$BACKUP_DIR/uploads-$STAMP.tar.gz" \
  -C /var/www/gips/storage/app/private . 2>/dev/null || true

echo "==> rotation ($RETENTION_DAYS days)"
sudo find "$BACKUP_DIR" -type f -name '*.gz' -mtime +"$RETENTION_DAYS" -delete

echo "==> contents"
sudo ls -lh "$BACKUP_DIR" | tail -5
