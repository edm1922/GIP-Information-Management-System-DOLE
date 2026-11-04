# GIMS â€” Azure VM deployment (Option B: VM + Azure Database for MySQL)

Target: **Ubuntu 24.04 LTS**, region **Southeast Asia (Singapore)**, nginx + PHP 8.3-FPM,
app on the VM, MySQL on **Azure Database for MySQL Flexible Server** (TLS required),
uploaded files on the VM's persistent OS disk.

> **Admin username:** 	ripz19 (replace it if your VM uses a different account).

> The app is server-rendered Laravel: one process serves both UI and backend.
> No code changes are needed beyond `.env`; `config/database.php` already reads
> `MYSQL_ATTR_SSL_CA` for the Azure MySQL certificate.

---

## 0. Azure portal (do these first)

1. **Recreate the VM in Southeast Asia** (the current one is in `eastus`):
   - Delete resource group `GIMS_group` (VM is empty) and recreate the VM in **Southeast Asia**.
   - Size **Standard_D2alds_v7** (or B2s) is fine. Image: **Ubuntu 24.04 LTS (Gen2)**.
   - Under *Networking â†’ Public inbound ports*: **None** (we open only what we need next).
   - Give the public IP a **DNS name label**, e.g. `gims-barmm` â†’
     hostname `gims-barmm.southeastasia.cloudapp.azure.com`.
2. **NSG rules** (`GIMS-nsg`):
   | Priority | Port | Source | Notes |
   |---|---|---|---|
   | 100 | 443 | Any | HTTPS |
   | 110 | 80 | Any | HTTP â†’ HTTPS |
   | 300 | 22 | **your public IP/32** | SSH (replace the existing `*` rule) |
3. **Create MySQL Flexible Server** (same region):
   - MySQL **8.0**, Burstable **B1ms**, admin password in a vault.
   - Networking: **Public access**; firewall rule = the VM's public IP; *Allow Azure services* **OFF**; TLS required.
   - After creation: create database **`gips_db`** and app user:
     ```sql
     CREATE DATABASE gips_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER 'gimsapp'@'%' IDENTIFIED BY '<strong-password>';
     GRANT ALL PRIVILEGES ON gips_db.* TO 'gimsapp'@'%';
     FLUSH PRIVILEGES;
     ```
   - Backups: retention **14â€“30 days**.
4. **Do not enable auto-shutdown.** Enable **Azure Backup** for the VM (daily, 30 days).

---

## 1. Server bootstrap

SSH in: `ssh tripz19@<public-ip>` (or the cloudapp hostname), then:

```bash
git clone https://github.com/edm1922/GIP-Information-Management-System-DOLE.git ~/gips-deploy
cd ~/gips-deploy
sudo APP_HOST=gims-barmm.southeastasia.cloudapp.azure.com bash deploy/azure/01-setup-server.sh
```

This installs nginx, PHP 8.3-FPM + extensions, Composer, certbot, ufw, fail2ban,
raises upload limits to 12 MB, downloads the DigiCert root for Azure MySQL TLS,
and installs the nginx vhost + PHP-FPM pool.

## 2. `.env`

```bash
sudo mkdir -p /var/www/gips
sudo cp ~/gips-deploy/deploy/azure/env.production.example /tmp/env.production
sudo nano /tmp/env.production      # APP_KEY later, DB_HOST/DB_USERNAME/DB_PASSWORD
```

`APP_KEY` is generated in step 3 (`php artisan key:generate`).

## 3. Deploy the app

```bash
cd ~/gips-deploy
sudo APP_HOST=gims-barmm.southeastasia.cloudapp.azure.com bash deploy/azure/02-deploy-app.sh
```

Then create the key and run migrations (the script prints the exact commands if `.env` is missing):

```bash
cd /var/www/gips
sudo -u www-data cp /tmp/env.production .env
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force       # schema only â€” data comes in step 5
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo systemctl reload php8.3-fpm nginx
```

Verify: `curl -I https://<host>` after step 4, or `curl -I -H 'Host: <host>' http://127.0.0.1`.

## 4. TLS

```bash
sudo APP_HOST=gims-barmm.southeastasia.cloudapp.azure.com bash deploy/azure/03-ssl.sh
```

## 5. Data + uploads

**From the local XAMPP machine** (not the VM) â€” see `deploy/azure/05-migrate-data.php`:

```bash
cd C:\xampp\htdocs\GIPS
copy deploy\azure\.migrate.env.example deploy\azure\.migrate.env
# edit .migrate.env with the Azure MySQL host/user/password
php deploy\azure\05-migrate-data.php --dry-run
php deploy\azure\05-migrate-data.php
```

Uploaded DTR scans (36 files, 0.5 MB):

```powershell
scp -r "C:\xampp\htdocs\GIPS\storage\app\private\intern-docs" tripz19@<public-ip>:/tmp/intern-docs
```
```bash
sudo -u www-data mkdir -p /var/www/gips/storage/app/private
sudo cp -r /tmp/intern-docs /var/www/gips/storage/app/private/
sudo chown -R www-data:www-data /var/www/gips/storage/app/private
```

## 6. Scheduler, backups, monitoring

```bash
sudo bash deploy/azure/04-backups.sh --install   # cron: nightly DB + uploads backup
sudo crontab -u www-data -l 2>/dev/null   # verify scheduler line
```

- **Scheduler**: `* * * * * cd /var/www/gips && php artisan schedule:run` â€” the app timezone is
  `UTC`, so 00:05/00:10 UTC = 08:05/08:10 Manila.
- **Backups**: Azure MySQL automated backups + nightly `mysqldump`/uploads archive in `/var/backups/gips`
  (14-day rotation) + Azure Backup for the VM disk.
- **Monitoring**: Azure Monitor alert on CPU/disk, plus an availability test against `https://<host>/login`.

## 7. Cutover checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production` in `/var/www/gips/.env`
- [ ] `APP_URL=https://<host>` and `MYSQL_ATTR_SSL_CA` set
- [ ] Seeded passwords changed: `admin`, `peso_manager`, `juandc`
- [ ] Login works for admin + intern; DTR upload (10 MB) works; preview/download works
- [ ] Folder browser + search work; 201 Files tab works
- [ ] Reports CSV download works
- [ ] Scheduled commands run: `sudo -u www-data php artisan interns:check-contract-expiry --dry-run` (no `--dry-run` flag â€” check `list`)
- [ ] Backups exist in `/var/backups/gips` and restore has been tested once
- [ ] Local XAMPP app kept read-only for 1 week before decommissioning

## Not needed on this stack

- `php artisan storage:link` (uploads are streamed through controllers)
- `php artisan route:cache` â€” **fails** on this app: `routes/web.php` has Closure routes
- Meilisearch (`SCOUT_DRIVER=collection`)

---

## Current deployment status (live)

| Item | Value |
|---|---|
| VM | `GIMS` · `20.191.145.6` · Ubuntu 24.04.5 · Southeast Asia |
| SSH | `ssh -i <key>.pem tripz19@20.191.145.6` (key-only, passwordless sudo) |
| App path | `/var/www/gips` (source copied; not a git checkout yet) |
| PHP / web | PHP 8.3.6 FPM + nginx 1.24 (vhost server_name: `20.191.145.6` until the DNS label is set) |
| Database | Azure Database for MySQL 8.4 · `gims-db.mysql.database.azure.com` · `gips_db` · app user `gimsapp` (random password in `.env`) |
| Migrations | all 60 ran on Azure MySQL |
| Data | users 3 · interns 2 · documents 3 · dtr_days 24 · audit_logs 74 · notifications 26 · companies 4 (rendered hours 104.2 — verified equal to local) |
| Uploads | 39 files in `storage/app/private/intern-docs` (paths verified against `documents.file_path`) |
| Scheduler | `www-data` crontab: `* * * * * php artisan schedule:run` |
| Backups | `/etc/cron.d/gips-backup` nightly 02:15 ? `/var/backups/gips` (DB dump + uploads tar, 14-day rotation); backup privileges granted to `gimsapp` |
| Firewall | ufw active; **NSG 80/443 still needs opening** |
| TLS | pending — needs the `gims-barmm` DNS label on `GIMS-ip` before `certbot` |

Local authentication verified against Azure MySQL: `admin`, `peso_manager`, `juandc` all `AUTH_OK`; `GET /login` ? 200.

> Note: `.env` has `SESSION_SECURE_COOKIE=true`, so the app must be used over **HTTPS**; plain `http://20.191.145.6` will not keep a login session (by design).
