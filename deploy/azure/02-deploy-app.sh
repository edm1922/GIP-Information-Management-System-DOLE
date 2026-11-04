#!/usr/bin/env bash
# GIMS — clone/update the app and build production caches.
# Usage: sudo APP_HOST=<hostname> bash deploy/azure/02-deploy-app.sh
set -euo pipefail

APP_DIR=/var/www/gips
REPO="${REPO:-https://github.com/edm1922/GIP-Information-Management-System-DOLE.git}"
BRANCH="${BRANCH:-master}"
APP_HOST="${APP_HOST:-}"

if [ ! -d "$APP_DIR/.git" ]; then
  echo "==> clone $REPO ($BRANCH) -> $APP_DIR"
  sudo mkdir -p "$APP_DIR"
  sudo chown "$USER":www-data "$APP_DIR"
  git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
else
  echo "==> updating existing checkout"
  sudo -u www-data git -C "$APP_DIR" fetch --all --prune
  sudo -u www-data git -C "$APP_DIR" reset --hard "origin/$BRANCH"
fi

cd "$APP_DIR"

echo "==> composer (no dev dependencies)"
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f "$APP_DIR/.env" ]; then
  cat >&2 <<EOF
No .env found at $APP_DIR/.env.

Create it first:
  sudo cp $APP_DIR/deploy/azure/env.production.example /tmp/env.production
  sudo nano /tmp/env.production        # DB_HOST, DB_USERNAME, DB_PASSWORD, APP_URL
  sudo cp /tmp/env.production $APP_DIR/.env
  sudo chown www-data:www-data $APP_DIR/.env
  sudo chmod 640 $APP_DIR/.env
  sudo -u www-data php artisan key:generate --force
Then re-run this script.
EOF
  exit 1
fi

echo "==> permissions"
sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" "$APP_DIR/.env"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;

echo "==> migrations"
sudo -u www-data php artisan migrate --force

echo "==> caches (route:cache skipped — routes/web.php has Closure routes)"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache || true

if [ -n "$APP_HOST" ]; then
  echo "==> nginx vhost for $APP_HOST"
  sudo cp "$APP_DIR/deploy/azure/nginx-gips.conf" /etc/nginx/sites-available/gips
  sudo sed -i "s/__APP_HOST__/${APP_HOST}/g" /etc/nginx/sites-available/gips
  sudo ln -sf /etc/nginx/sites-available/gips /etc/nginx/sites-enabled/gips
  sudo rm -f /etc/nginx/sites-enabled/default
  sudo nginx -t && sudo systemctl reload nginx
fi

sudo systemctl reload php8.3-fpm
echo "Deploy complete. Test: curl -I http://127.0.0.1/ -H 'Host: ${APP_HOST:-localhost}'"
