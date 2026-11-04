#!/usr/bin/env bash
# GIMS - Ubuntu 24.04 server bootstrap (nginx, PHP 8.3, certbot, firewall, hardening)
# Usage: sudo APP_HOST=<your-hostname> bash deploy/azure/01-setup-server.sh
set -euo pipefail

APP_HOST="${APP_HOST:?set APP_HOST (e.g. gims-barmm.southeastasia.cloudapp.azure.com)}"

echo "==> apt packages"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y \
  nginx php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-zip php8.3-mbstring \
  php8.3-bcmath php8.3-curl php8.3-xml php8.3-intl php8.3-opcache \
  git unzip composer certbot python3-certbot-nginx ufw fail2ban unattended-upgrades \
  default-mysql-client

echo "==> PHP tuning (10 MB DTR uploads)"
PHP_INI=/etc/php/8.3/fpm/php.ini
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 12M/' "$PHP_INI"
sudo sed -i 's/^post_max_size.*/post_max_size = 12M/' "$PHP_INI"
sudo sed -i 's/^memory_limit.*/memory_limit = 256M/' "$PHP_INI"
sudo sed -i 's/^;*max_execution_time.*/max_execution_time = 120/' "$PHP_INI"

echo "==> OPcache (production: deploy scripts reload php-fpm)"
sudo tee /etc/php/8.3/fpm/conf.d/99-gips.ini >/dev/null <<'INI'
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.interned_strings_buffer=16
INI

echo "==> PHP-FPM pool for GIMS"
sudo tee /etc/php/8.3/fpm/pool.d/gips.conf >/dev/null <<'POOL'
[www]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 25
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 500
POOL

echo "==> Azure MySQL TLS root certificate"
sudo curl -fsSL https://cacerts.digicert.com/DigiCertGlobalRootG2.crt.pem \
  -o /etc/ssl/certs/DigiCertGlobalRootG2.crt.pem
sudo chmod 644 /etc/ssl/certs/DigiCertGlobalRootG2.crt.pem

echo "==> nginx vhost"
sudo cp "$(dirname "$0")/nginx-gips.conf" /etc/nginx/sites-available/gips
sudo sed -i "s/__APP_HOST__/${APP_HOST}/g" /etc/nginx/sites-available/gips
sudo ln -sf /etc/nginx/sites-available/gips /etc/nginx/sites-enabled/gips
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx

echo "==> firewall (NSG is the primary control; ufw is defence in depth)"
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable

echo "==> unattended security updates"
sudo dpkg-reconfigure -f noninteractive unattended-upgrades || true

echo "==> services"
sudo phpenmod -v 8.3 opcache
sudo systemctl enable --now php8.3-fpm nginx
sudo systemctl reload php8.3-fpm

echo "==> versions"
php -v | head -1
nginx -v
echo "Done. Next: deploy/azure/env.production.example -> /var/www/gips/.env, then 02-deploy-app.sh"
