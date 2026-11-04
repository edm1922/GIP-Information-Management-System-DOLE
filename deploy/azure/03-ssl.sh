#!/usr/bin/env bash
# GIMS — issue the Let's Encrypt certificate for the Azure hostname/domain.
# Usage: sudo APP_HOST=gims-barmm.southeastasia.cloudapp.azure.com EMAIL=you@example.com \
#          bash deploy/azure/03-ssl.sh
set -euo pipefail

APP_HOST="${APP_HOST:?set APP_HOST to the public hostname}"
EMAIL="${EMAIL:?set EMAIL for certificate expiry notices}"

echo "Checking $APP_HOST resolves to this server…"
getent hosts "$APP_HOST" || { echo "DNS does not resolve for $APP_HOST"; exit 1; }

sudo certbot --nginx -d "$APP_HOST" --redirect --agree-tos --no-eff-email -m "$EMAIL"

echo "==> renewal timer"
sudo systemctl list-timers 'certbot*' --no-pager || true
sudo certbot renew --dry-run

echo "HTTPS enabled for https://$APP_HOST"
