# GIMS self-hosted backend (Ubuntu VM + Docker Compose + Cloudflare Tunnel)

This directory is the complete backend deployment for the migration: Laravel 12
API on an Ubuntu Server VM hosted by the owner's Windows 11 machine, reachable
only through a Cloudflare Tunnel. The Next.js frontend lives on Vercel.

Nothing here is executed automatically. `scripts/deploy.sh --migrate` and
`scripts/restore.sh` are the only database-changing commands, and both are
gated (verified backup, typed confirmation, explicit unlock).

---

## 1. What is in here

| Path | Purpose |
|---|---|
| `docker-compose.yml` | Services: `app` (PHP-FPM), `queue`, `scheduler`, `nginx`, `postgres`, `meilisearch`, `cloudflared` |
| `app/Dockerfile` | PHP 8.4-FPM image with pdo_pgsql, gd, intl, zip, opcache, pcntl |
| `app/php.ini`, `app/www.conf` | Upload limits (10 MB validation → 12 MB PHP/nginx), OPcache, FPM pool sizing |
| `nginx/Dockerfile`, `nginx/default.conf` | Static assets + FastCGI proxy, health endpoint, security headers |
| `.env.production.example` | Template for the real `.env` (never commit `.env`) |
| `cloudflared/config.yml.example` | Optional locally-managed tunnel (the default is a dashboard token) |
| `scripts/preflight.sh` | Read-only validation of host, `.env`, Compose and exposure |
| `scripts/deploy.sh` | Build, start, cache config/views, optional gated migrations |
| `scripts/backup.sh` | `pg_dump -Fc` + storage archive, integrity-verified, retention |
| `scripts/restore.sh` | **Destructive** restore: validates artifacts, safety-backups, restores |
| `scripts/verify.sh` | Container, DB, HTTP, CSRF, CORS, header and exposure checks |

## 2. Architecture

```
Browser ──HTTPS──▶ Vercel (Next.js)  app.example.com
   │                     │
   │  fetch(credentials: include, X-XSRF-TOKEN)
   ▼                     ▼
Cloudflare edge ──▶ Cloudflare Tunnel (outbound only, no port forwarding)
                         │
                  ┌──────▼────────── Ubuntu VM (Hyper-V, Windows 11 host) ──┐
                  │ cloudflared ──▶ nginx:80 (private Docker network)      │
                  │                   │ FastCGI                           │
                  │                   ▼                                  │
                  │            app (PHP-FPM)  ◀── queue / scheduler       │
                  │                   │                                  │
                  │       ┌───────────┴───────────┐                     │
                  │       ▼                       ▼                     │
                  │  postgres:5432          meilisearch:7700             │
                  │  (volume pgdata)        (volume meili_data)           │
                  │  uploads/logs: volume app_storage                     │
                  └──────────────────────────────────────────────────────┘
```

* Same registrable domain (`example.com`) for frontend and API → same-site
  cookies, so `SESSION_SAME_SITE=lax` works.
* Only `127.0.0.1:8080` (nginx) is published. PostgreSQL, Meilisearch and FPM
  have **no** host port.
* TLS terminates at Cloudflare; the tunnel connection is outbound, so the home
  network needs no inbound rule.

## 3. Windows 11 host preparation (once)

Run PowerShell **as Administrator**.

### 3.1 Virtualisation and power

```powershell
# Hyper-V (requires Windows 11 Pro/Enterprise; reboot afterwards)
Enable-WindowsOptionalFeature -Online -FeatureName Microsoft-Hyper-V-All -All

# A server must never sleep; disable hibernate to avoid resume surprises.
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change monitor-timeout-ac 15
powercfg /hibernate off
```

In the machine's BIOS/UEFI set **Restore on AC Power Loss = Power On**.

### 3.2 Create the VM

A *bridged* external switch gives the VM a normal LAN address (recommended: it
also keeps working when the host's VPN is off). Replace `Ethernet` with the
adapter name from `Get-NetAdapter`.

```powershell
New-VMSwitch -Name "GIMS External" -NetAdapterName "Ethernet" -AllowManagementOS $true

New-VM -Name "gims-backend" `
  -MemoryStartupBytes 8GB `
  -Generation 2 `
  -NewVHDPath "D:\Hyper-V\gims-backend\disk.vhdx" `
  -NewVHDSizeBytes 120GB `
  -SwitchName "GIMS External"

Set-VM -Name "gims-backend" -ProcessorCount 4 `
  -AutomaticStartAction Start -AutomaticStartDelay 30 `
  -AutomaticStopAction ShutDown -CheckpointType Disabled

# Fixed memory: predictable PHP-FPM/PostgreSQL behaviour.
Set-VMMemory -VMName "gims-backend" -DynamicMemoryEnabled $false -StartupBytes 8GB

# Boot from the Ubuntu Server 24.04 ISO.
Add-VMDvdDrive -VMName "gims-backend" -Path "D:\ISO\ubuntu-24.04-live-server-amd64.iso"
Set-VMFirmware -VMName "gims-backend" -FirstBootDevice (Get-VMDvdDrive -VMName "gims-backend")
Start-VM -Name "gims-backend"
```

Then connect with `vmconnect.exe localhost gims-backend` (or Hyper-V Manager)
and install Ubuntu Server 24.04 LTS with OpenSSH enabled. Minimum sizing:
2 vCPU / 4 GB for light use; 4 vCPU / 8 GB recommended (PHP-FPM 20 workers,
PostgreSQL, Meilisearch).

> Keep the VM's network address stable: either reserve its MAC address in the
> router's DHCP table or give it a static address with netplan. The tunnel is
> outbound, so the address only matters to you (SSH), not to the internet.

## 4. Ubuntu VM preparation

```bash
sudo apt update && sudo apt -y full-upgrade
sudo apt -y install ca-certificates curl gnupg git ufw
sudo timedatectl set-timezone UTC      # app timestamps are UTC
sudo hostnamectl set-hostname gims-backend
```

### 4.1 Docker Engine + Compose v2

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt update
sudo apt -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"   # log out/in once for this to apply
```

Verify: `docker run --rm hello-world`.

### 4.2 Firewall

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw enable
```

> Docker publishes container ports by writing its own iptables rules, which
> bypass UFW. That is why the Compose file binds nginx to `127.0.0.1` only and
> why `preflight.sh` fails on any non-loopback `ports:` entry.

### 4.3 Unattended security updates

```bash
sudo apt -y install unattended-upgrades
sudo dpkg-reconfigure --priority=low unattended-upgrades   # enable automatic updates
```

> Kernel/Docker updates need a reboot. Schedule one monthly and check
> `docker compose ps` afterwards; all services use `restart: unless-stopped`.

## 5. Deploy the code

```bash
sudo mkdir -p /srv/gims && sudo chown "$USER":"$USER" /srv/gims
git clone <repository-url> /srv/gims
cd /srv/gims
git checkout <release-tag-or-sha>       # deploy a known commit, not a moving branch
```

From Windows you can also `rsync -av --exclude vendor --exclude node_modules ./ user@gims-backend:/srv/gims/`.

## 6. Configure `.env`

```bash
cd /srv/gims/deploy/selfhosted
cp .env.production.example .env
chmod 600 .env

# Values to paste into .env:
openssl rand -base64 32     # APP_KEY      → APP_KEY=base64:<output>
openssl rand -base64 32     # DB_PASSWORD  → DB_PASSWORD=<output>
openssl rand -base64 32     # MEILISEARCH_KEY (must be >= 16 chars)
nano .env
```

Required edits (everything marked `CHANGE-ME` / `example.com`):

| Variable | Value |
|---|---|
| `APP_KEY` | `base64:` + 32 random bytes |
| `APP_URL` | Public API host, e.g. `https://api.example.com` |
| `FRONTEND_URL` | Vercel host, e.g. `https://app.example.com` |
| `DB_PASSWORD` | long random value (no literal `$`, or write `$$`) |
| `MEILISEARCH_KEY` | long random value (no literal `$`) |
| `SESSION_DOMAIN` | `.example.com` (shared parent of app + api) |
| `SANCTUM_STATEFUL_DOMAINS` | `app.example.com,api.example.com` |
| `CORS_ALLOWED_ORIGINS` | `https://app.example.com` |
| `CLOUDFLARE_TUNNEL_TOKEN` | tunnel token from the Cloudflare dashboard |

Rules that `preflight.sh` enforces:

* `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`,
  `DB_HOST=postgres`, `DB_SSLMODE=prefer` (there is no TLS inside the Docker
  network).
* `SANCTUM_STATEFUL_DOMAINS` must list the real frontend host — **not `*`**.
  Without it Sanctum never starts the session middleware and every
  authenticated call returns `401`.
* `CORS_ALLOWED_ORIGINS` must contain `FRONTEND_URL` exactly, and must not be
  `*` with credentials enabled.

> Compose interpolates `DB_*`, `MEILISEARCH_KEY` and `CLOUDFLARE_TUNNEL_TOKEN`
> from `.env`. A literal `$` in those values is treated as a variable
> reference; escape it as `$$`.

> `php artisan config:cache` bakes `.env` into `bootstrap/cache/config.php`.
> **After editing `.env`, re-run `./scripts/deploy.sh`** (or
> `docker compose exec -u www-data app php artisan config:cache`), otherwise the
> containers keep using the old values.

## 7. Cloudflare Tunnel and Vercel

### 7.1 Tunnel (dashboard-managed, recommended)

1. Add the domain to Cloudflare and let Cloudflare manage its DNS.
2. Zero Trust → **Networks → Tunnels → Create a tunnel** → *Cloudflared* →
   name it `gims-backend`.
3. Copy the connector token and put it in `.env` as
   `CLOUDFLARE_TUNNEL_TOKEN`.
4. Add a **public hostname**:
   * Subdomain `api`, domain `example.com`
   * Service: **HTTP** → `nginx:80`
     (`nginx` is the Compose service name — not `localhost`, because the
     connector runs in its own container.)
5. Cloudflare creates the proxied `CNAME` automatically. No A record, no port
   forwarding, no inbound firewall rule.
6. Optional hardening: put **Cloudflare Access** in front of `/api/v1/settings/*`
   or the whole hostname, and enable *Always Use HTTPS* + HSTS.

Locally-managed tunnels (own `cert.pem` + credentials JSON) are supported by
`cloudflared/config.yml.example`; see the header of that file for the Compose
changes.

### 7.2 Vercel (frontend)

* Set the project's production domain to `app.example.com`.
* Environment variable: `NEXT_PUBLIC_API_URL=https://api.example.com`.
* The SPA must send credentialed requests (`fetch(..., { credentials: 'include' })`),
  call `GET /sanctum/csrf-cookie` before writes, and echo the `XSRF-TOKEN`
  cookie in the `X-XSRF-TOKEN` header. `401`/`419` must be handled globally
  (session expiry / CSRF mismatch).

The three settings that must agree:

| Where | Setting |
|---|---|
| Laravel `SESSION_DOMAIN` | `.example.com` |
| Laravel `SANCTUM_STATEFUL_DOMAINS` | `app.example.com,api.example.com` |
| Laravel `CORS_ALLOWED_ORIGINS` | `https://app.example.com` |

## 8. First deployment

### 8.1 Fresh (empty) database

```bash
cd /srv/gims/deploy/selfhosted
./scripts/preflight.sh
./scripts/deploy.sh --build --initialize --seed-reference-data
```

`--initialize` refuses to run unless the `public` schema has **zero** tables.
It requires `ALLOW_MIGRATIONS=yes` in `.env` and a typed `INITIALIZE`
confirmation.

Then set `ALLOW_MIGRATIONS=no`, log in as `admin` (password
`admin` + `APP_PASSWORD_SUFFIX`) and **change every seeded password**.
`admin`, `peso_manager` and `juandc` are development fixtures, not production
accounts.

> Do **not** run `php artisan db:seed`. `DatabaseSeeder` executes
> `SET FOREIGN_KEY_CHECKS = 0` (MySQL-only) and truncates every business table.
> `--seed-reference-data` runs only the three safe `firstOrCreate` seeders.

### 8.2 Restore the existing dataset (normal cutover)

```bash
# On the source host: dump MySQL/PostgreSQL as appropriate, then copy the
# artifacts to the VM (scp/rsync), e.g. into /srv/gims/deploy/selfhosted/backups/import/
./scripts/restore.sh --db /path/db.dump --storage /path/storage.tar.gz --yes
./scripts/verify.sh
```

`restore.sh` validates checksums and the dump **before** stopping anything,
takes a fresh safety backup, requires `--yes` plus a typed `RESTORE`, then
drops/recreates the database and restores `storage/app`.

> **Decision gate — see §13.1.** `pg_restore` reads PostgreSQL dumps only. The
> local development database and the previous Azure deployment are MySQL
> (`gips_db`). If the authoritative dataset must stay MySQL, the data has to be
> migrated to PostgreSQL first (or this stack has to be switched to MySQL).

## 9. Day-2 operations

```bash
cd /srv/gims/deploy/selfhosted

./scripts/verify.sh                 # full health/security check
./scripts/verify.sh --public        # also test https://api.example.com via the tunnel
docker compose ps                   # service + health status
docker compose logs -f --tail=100 app
docker compose logs -f queue
docker compose exec -u www-data app php artisan about

# Maintenance window
docker compose exec -u www-data app php artisan down --secret="<token>"
docker compose exec -u www-data app php artisan up

# Update to a new commit
git fetch --all && git checkout <new-tag-or-sha>
./scripts/deploy.sh                 # config/view cache + restart
./scripts/deploy.sh --migrate       # only when the release adds migrations
```

`deploy.sh` deliberately does **not** run `route:cache`: `/api/v1/health` is a
closure route and cannot be serialized. `config:cache` and `view:cache` are
applied on every deploy.

Rollback = `git checkout <previous-sha> && ./scripts/deploy.sh --build`. Image
rollback is not automated; if you need instant rollback, tag images per release
(`docker tag gims-app:latest gims-app:<sha>`) before deploying.

## 10. Backups

```bash
./scripts/backup.sh                  # DB + storage, verified, retention applied
./scripts/backup.sh --db-only        # skip the (slow) file archive
```

Artifacts land in `backups/<UTC timestamp>/`:

| File | Content |
|---|---|
| `db.dump` | `pg_dump --format=custom` (no owners/privileges) |
| `db.toc` | `pg_restore --list` output used as the integrity proof |
| `storage.tar.gz` | `storage/app` (uploaded documents) |
| `SHA256SUMS`, `MANIFEST.txt` | checksums + metadata (commit, sizes, table count) |

`.last-verified-backup` is written **only after** both artifacts pass their
integrity checks, and `deploy.sh --migrate` refuses to run without a marker
younger than 24 hours.

Daily cron (adjust the user and paths):

```bash
sudo tee /etc/cron.d/gims-backup >/dev/null <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 2 * * * gims cd /srv/gims/deploy/selfhosted && BACKUP_HOOK='rclone sync /srv/gims/deploy/selfhosted/backups remote:gims-backups' ./scripts/backup.sh >> /srv/gims/deploy/selfhosted/backups/cron.log 2>&1
EOF
sudo chmod 644 /etc/cron.d/gims-backup
```

`BACKUP_HOOK` is the off-host copy. **Backups on the same disk are not
backups** — point it at another machine, a NAS or object storage (rclone/rsync)
and test retrieval weekly.

### Restore drill (quarterly; does not touch production)

```bash
cd /srv/gims/deploy/selfhosted
LATEST="$(ls -1d backups/20* | tail -1)"

docker compose exec -T postgres createdb -U gims gims_restore_test
docker compose exec -T postgres pg_restore --no-owner --no-privileges \
  -U gims -d gims_restore_test < "$LATEST/db.dump"

# Compare row counts with production
docker compose exec -T postgres psql -U gims -d gims_restore_test -c \
  "select 'users' t, count(*) from users union all select 'interns', count(*) from interns union all select 'documents', count(*) from documents;"

docker compose exec -T postgres dropdb -U gims gims_restore_test
```

Also spot-check that `documents.file_path` values exist in a restored
`storage.tar.gz` before declaring a drill successful.

## 11. Verification checklist before cutover

- [ ] `preflight.sh` passes with zero failures.
- [ ] `verify.sh` passes (all checks) and `verify.sh --public` sees `cf-ray`.
- [ ] Login works from the real Vercel domain; DevTools shows `gims_session`
      on `.example.com`, `Secure`, `HttpOnly`.
- [ ] Forced-password-change flow returns `403 PASSWORD_CHANGE_REQUIRED` and
      allows the password change endpoint.
- [ ] Upload a document (≤10 MB), preview and download it as the owning intern
      and as an admin.
- [ ] Wrong file type / >10 MB is rejected with `422` and a readable message.
- [ ] A queued job (e.g. notification) is processed; `failed_jobs` stays empty.
- [ ] Scheduler runs (`docker compose logs scheduler` shows a run each minute).
- [ ] `backup.sh` then the restore drill succeed.
- [ ] Host reboot: VM starts, Docker starts, `verify.sh` passes without manual
      intervention.

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Cloudflare shows **502** | nginx/app down, or tunnel points at the wrong service | `docker compose ps`; tunnel service must be `http://nginx:80` |
| Every authenticated API call is **401** | `SANCTUM_STATEFUL_DOMAINS` missing the frontend host; SPA not sending credentials | fix `.env`, `./scripts/deploy.sh`; check `Origin`/`Referer` in DevTools |
| **419 CSRF token mismatch** | wrong `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, or missing `X-XSRF-TOKEN` | `.example.com`, `true`; send the header from the cookie |
| Login **500** | `APP_KEY` missing/invalid | `docker compose logs app`; regenerate and re-deploy |
| Config change has no effect | `bootstrap/cache/config.php` is stale | re-run `./scripts/deploy.sh` |
| `SQLSTATE ... could not connect` | `DB_SSLMODE=require`, wrong password, or `$` in the password | use `prefer`; check `docker compose config` output |
| `write ... storage/...: Permission denied` | a command wrote files as root | `docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache` |
| `refusing to initialize` | database already has tables | use `deploy.sh --migrate` |
| `migrations are locked` | safety switch | set `ALLOW_MIGRATIONS=yes`, take a backup, retry, then set it back |
| `pg_restore: unsupported version` | dump from a newer PostgreSQL | restore with the matching server image |
| Containers did not start after reboot | Docker not enabled / VM not auto-starting | `sudo systemctl enable docker`; `Set-VM -AutomaticStartAction Start` |
| Queue backlog grows | worker crash-looping | `docker compose logs queue`; `docker inspect` restart count |
| Meilisearch container unhealthy | API key shorter than 16 chars | fix `MEILISEARCH_KEY` |

Useful one-liners:

```bash
docker compose config --quiet            # validate compose + interpolation
docker compose exec -T postgres psql -U gims -d gims -c '\dt' | head -40
docker compose exec -u www-data app php artisan migrate:status
docker compose exec -u www-data app php artisan queue:failed
```

## 13. Open decision gates and known findings

### 13.1 PostgreSQL vs MySQL (blocking the data migration)

The specification and `.env.example` target **PostgreSQL**, which is what this
stack deploys. However the current local `.env` and the previous Azure
deployment use **MySQL** (`gips_db`). PostgreSQL is not a drop-in for a MySQL
dump, and the two differ in case-sensitive `LIKE`/sorting behaviour.

Before restoring real data, one of these must be chosen explicitly:

1. **Migrate to PostgreSQL (spec-compliant).** Plan a one-off data migration
   (pgloader or a Laravel-based export/import), compare row counts and
   relationships, and run the restore drill. Budget time for collation/search
   behaviour checks.
2. **Keep MySQL.** Replace the `postgres` service with `mysql:8.4`, change
   `DB_CONNECTION=mysql`/`DB_SSLMODE`, and adapt `backup.sh`/`restore.sh`
   (`mysqldump`/`mysql`) and the schema checks in `deploy.sh`.

No command in this directory has been run against any real database.

### 13.2 Meilisearch is provisioned but idle

No model in the current codebase uses `Laravel\Scout\Searchable`, and
`config/scout.php` still references legacy `App\Models\Document` and
`App\Models\Employee` classes that no longer exist. Consequently:

* `scout:sync-index-settings` fails (it tries to instantiate those classes), so
  `deploy.sh` skips it unless `--sync-search` is passed;
* `scout:import` has nothing to import;
* search in the current application is database-driven, not Meilisearch-driven.

Meilisearch is kept in the stack because the target architecture requires it and
the frontend migration may add real full-text search. If it stays unused, the
container can be removed from `docker-compose.yml` to save ~150 MB RAM.

### 13.3 Other operational notes

* **`php artisan serve` on Windows** is unrelated to this deployment (nginx +
  PHP-FPM is used here). Locally it needs `--no-reload`, because Laravel's
  reload mode strips `SystemRoot` and PHP cannot bind.
* **Session cookie domain.** `SESSION_DOMAIN=.example.com` is required for the
  `app.` + `api.` split. If the frontend ever moves to a different registrable
  domain, Sanctum cookie auth stops working and the API must switch to token
  auth instead.
* **Single point of failure.** Home power, ISP and the Windows host are all
  single points. A UPS and monitoring of `https://api.example.com/api/v1/health`
  are strongly recommended.
* **Secrets.** `.env`, tunnel credentials and backups are gitignored. Keep an
  encrypted copy of `.env` in a password manager — losing `APP_KEY` invalidates
  encrypted columns and existing sessions.
* **Resource sizing.** `pm.max_children = 20` assumes ≥4 GB for PHP. For a 2 GB
  VM, lower it in `app/www.conf` (≈10) and add swap.
