# Phase 0 — Self-Hosted Deployment Plan (Design)

**Target architecture (spec):** Next.js frontend on Vercel; Laravel 12 API on owner’s Windows 11 computer running Ubuntu Server VM (prefer Hyper-V), exposed via Cloudflare Tunnel; PostgreSQL + Meilisearch + queue workers + scheduler + private storage on backend host. **No VPS; no Laravel/Postgres/Meilisearch/queues on Vercel.**

**Status:** Design + **Phase 2 artifacts delivered** under `deploy/selfhosted/` (PHP-FPM/nginx images, Compose stack, preflight/deploy/backup/restore/verify scripts, production `.env` template, operator runbook in `deploy/selfhosted/README.md`). Nothing has been run against a real host, container runtime, or production database yet; see §10 for exactly what is and is not validated.

---

## 1. Component responsibilities

| Component | Host/platform | Responsibilities |
|---|---|---|
| Next.js app | Vercel | UI rendering/routing, forms/interactions, dashboards/tables/filters, uploads UI, previews UI, API calls to Laravel |
| Laravel 12 API | Ubuntu Server VM on Windows 11 | Auth/session/Sanctum, validation, authorization, business logic, DB access, file storage control, reports/exports, jobs, search orchestration, audit logging |
| PostgreSQL | Ubuntu VM (container/service) | Primary data store (existing DB preserved/migrated as approved) |
| Meilisearch | Ubuntu VM (container/service) | Search indexes; private/admin access only |
| Queue workers | Ubuntu VM | Background jobs (exports, document/search/bulk tasks as applicable) |
| Scheduler | Ubuntu VM | `schedule:run` / scheduled Artisan commands (e.g. intern checks) |
| Cloudflare Tunnel (`cloudflared`) | Ubuntu VM | Outbound-only public ingress for `api.example.com` to local Nginx |
| Nginx + PHP-FPM | Ubuntu VM | HTTP termination for Laravel API (behind tunnel) |

---

## 2. Domain, DNS, and ingress

### 2.1 Domains (configurable, no hardcoding)
- `gims.example.com` → Vercel (frontend)
- `api.example.com` → Cloudflare Tunnel → local Nginx/Laravel (backend)

Same registrable domain required for Sanctum SPA cookie auth.

### 2.2 Cloudflare + Vercel setup steps (operational checklist)
1. Register/acquire domain and add site/zone to Cloudflare.
2. Update registrar nameservers to Cloudflare-provided values and verify delegation.
3. Create Vercel project for Next.js frontend; add `gims.example.com`.
4. Configure Vercel-provided DNS records in Cloudflare (proxy/DNS mode per Vercel guidance, validated for chosen setup).
5. Create Cloudflare Tunnel for backend; install/configure `cloudflared` in Ubuntu.
6. Publish `api.example.com` via tunnel to internal Nginx endpoint (HTTP locally; HTTPS externally via Cloudflare).
7. Enforce HTTPS for all public traffic; verify certificate/TLS behavior end-to-end.
8. Validate tunnel auto-reconnect after service/VM/host restarts.

### 2.3 Exposure constraints
- **Expose only:** API hostname through tunnel.
- **Do not expose publicly:** PostgreSQL (5432), Meilisearch admin/API, SSH/RDP, Docker APIs, private storage paths, internal admin ports.
- No router port forwarding / static public IP required (tunnel outbound only).

---

## 3. Windows 11 host & Ubuntu VM

### 3.1 Host requirements (operational)
- Keep powered on 24/7; disable sleep/hibernation.
- Reserve local network address for VM (DHCP reservation/static as appropriate).
- Configure VM auto-start on boot and recovery after planned restarts.
- Schedule Windows updates/maintenance windows; maintain sufficient free SSD space.
- UPS for computer/router/modem recommended/required by spec.

### 3.2 Virtualization choice
- **Preferred:** Hyper-V (when supported) running Ubuntu Server VM.
- **If Hyper-V unavailable:** evaluate WSL2 + Ubuntu + systemd or another supported virtualization approach; document selected option, networking implications, and automatic startup behavior before production cutover.

### 3.3 Suggested initial VM sizing (adjust to hardware/load)
- 4 vCPU, 8 GB RAM, 100–150 GB SSD, bridged/NAT networking as appropriate for stable LAN access and tunnel egress.

---

## 4. Ubuntu runtime & service layout (recommended: Docker Compose)

### 4.1 Services (inside Ubuntu)
- `nginx` (or Caddy/Nginx fronting PHP-FPM) — routes API traffic to Laravel
- `php-fpm` / Laravel app container(s)
- `postgres`
- `meilisearch`
- `queue` worker(s) (`php artisan queue:work`/appropriate config)
- `scheduler` (`php artisan schedule:work` or cron-triggered `schedule:run`)
- `cloudflared`
- Optional: one-shot/init jobs for migrations (approval-gated), cache/config warmup, storage permissions

### 4.2 Persistence & state
- Docker named volumes/bind mounts for: PostgreSQL data, Meilisearch data, Laravel storage/uploads, logs (as needed), tunnel credentials/config.
- Ensure volumes survive container recreation, VM restarts, and app updates.

### 4.3 Process supervision & startup
- Enable Docker to start automatically on Ubuntu boot.
- Use Compose `restart: unless-stopped`/appropriate policies and health checks.
- Ensure Postgres, Meilisearch, queue workers, scheduler, Nginx/PHP, and `cloudflared` all start/restart automatically.
- Do **not** depend on Docker Desktop manual login/start on Windows.

### 4.4 Network/security inside VM/host
- Bind DB/Meilisearch to internal Docker network only; no host port publishing to LAN/WAN except what tunnel/Nginx strictly require.
- Apply firewall rules (Ubuntu UFW/firewalld and/or host firewall) to minimize exposed services.
- Restrict administrative access (SSH keys if used, no public SSH exposure).

### 4.5 Delivered artifacts (Phase 2)

| Path | Delivers |
|---|---|
| `deploy/selfhosted/docker-compose.yml` | `app` (PHP-FPM), `queue`, `scheduler`, `nginx`, `postgres:17`, `meilisearch:v1.16`, `cloudflared`; named volumes `pgdata`, `meili_data`, `app_storage`, `bootstrap_cache`; only `127.0.0.1:${NGINX_HOST_PORT:-8080}` published |
| `deploy/selfhosted/app/` | PHP 8.4-FPM image (pdo_pgsql/pdo_mysql, gd, intl, zip, opcache, pcntl), production `php.ini`, FPM pool sizing (20 children, 120 s timeout) |
| `deploy/selfhosted/nginx/` | Static assets + FastCGI proxy, `/healthz`, 12 MB body limit, security headers (HSTS/nosniff/frame/referrer), dotfile deny, real-IP trust limited to private ranges |
| `deploy/selfhosted/.env.production.example` | Full production env contract (Sanctum stateful domains, `SESSION_DOMAIN`, CORS, queue/cache, Meilisearch, tunnel token) |
| `deploy/selfhosted/scripts/preflight.sh` | Read-only host/`.env`/Compose validation; fails on non-loopback published ports (checked against resolved `docker compose config`) |
| `deploy/selfhosted/scripts/deploy.sh` | Build, up, gated `--initialize`/`--migrate`, `config:cache`/`view:cache`, health wait |
| `deploy/selfhosted/scripts/backup.sh` / `restore.sh` | Verified `pg_dump -Fc` + storage archive, retention, destructive restore with pre-flight validation and safety backup |
| `deploy/selfhosted/scripts/verify.sh` | Containers, DB, queue backlog, Meilisearch, `/healthz`, `/up`, `/api/v1/health`, XSRF cookie, JSON 401, CORS preflight, security headers, host port exposure, optional public HTTPS/`cf-ray` checks |

Safety gates implemented in the scripts: migrations require `ALLOW_MIGRATIONS=yes` **and** a `.last-verified-backup` marker younger than 24 h **and** a typed confirmation; `--initialize` refuses a non-empty database; `restore.sh` requires `--yes` plus a typed `RESTORE` and takes a safety backup first.

---

## 5. Laravel backend environment (production targets)

Configure via env (secrets not committed):
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.example.com`
- Database (`DB_*`), Meilisearch (`MEILISEARCH_*`, `SCOUT_*`), queue/cache/session/filesystems/mail/logging
- Sanctum/CORS/session: `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, cookie SameSite/HttpOnly, strict allowed origins
- Trusted proxy settings as required by tunnel (`TRUSTED_PROXIES` already supported in app bootstrap)

> **Sanctum stateful domains are load-bearing.** Sanctum only starts the session/cookie/CSRF middleware when the request `Origin`/`Referer` matches `SANCTUM_STATEFUL_DOMAINS`. Production must list the real frontend host (e.g. `app.example.com`), never `*`; otherwise authenticated SPA calls silently return `401`. Verified locally by `scripts/verify-api-csrf.ps1`.

> **`PHP_CLI_SERVER_WORKERS=4` in `.env` is development-only.** It is consumed by `php artisan serve` (and unsupported on Windows PHP, which lacks `fork`). Production runs nginx + PHP-FPM, where it has no effect; keep it out of the production `.env` to avoid confusion.

**Operational hardening:** config/route/view caching as appropriate for production, secure file permissions, least-privilege service users, dependency/OS updates on maintenance schedule.

---

## 6. Frontend deployment (Vercel)

- Deploy Next.js (App Router, TypeScript) project to Vercel.
- Env: `NEXT_PUBLIC_API_URL=https://api.example.com` (public only). No backend secrets.
- Configure custom domain `gims.example.com` and verify HTTPS.
- Ensure frontend uses credentialed requests to API and respects CSRF/session flow.

---

## 7. Deployment sequence (aligned to spec phases)

1. **Phase 2 infra bring-up (local/private):** provision VM + Ubuntu, install Docker/Compose, then `cd deploy/selfhosted && ./scripts/preflight.sh && ./scripts/deploy.sh --build` (add `--initialize --seed-reference-data` only for a brand-new empty database). Restore/configure the DB copy as approved for testing, then `./scripts/verify.sh`.
2. **Tunnel publish:** configure `cloudflared`, expose only API host, verify HTTPS and reconnect behavior.
3. **Phase 3-4 app integration:** deploy API-compatible backend and Next.js frontend against staging/production-like config; run integration tests.
4. **Phase 5 public cutover:** point frontend domain to Vercel, enable production API domain, validate auth/CORS/CSRF/files/reports/jobs/search.
5. **Phase 6 acceptance:** full workflow tests, restart/recovery drills, backup/restore verification, rollback readiness.

---

## 8. Runbook items (delivered in `deploy/selfhosted/README.md`)
- Service start/stop/restart/update commands (Compose and host). ✔
- Log locations and health checks for app, queue, scheduler, tunnel, DB, Meilisearch. ✔
- Certificate/tunnel troubleshooting and rollback steps. ✔
- Secret rotation and env management procedure (without committing `.env`). ✔
- Procedure for applying Laravel updates/migrations safely (backup + approval gates). ✔

## 9. Open items (require environment-specific decisions)
- Hyper-V vs WSL2/systemd fallback on target hardware.
- Exact VM resource allocation based on host capacity.
- LAN IP reservation method and router/admin constraints (without port forwarding).
- **Database engine for the authoritative dataset:** PostgreSQL (spec/`.env.example`, what the Compose stack runs) vs MySQL `gips_db` (local `.env` and the previous Azure deployment). `pg_restore` cannot read a MySQL dump, so this must be decided before restoring real data.
- WAF/rate-limiting rules in Cloudflare that do not break Sanctum SPA requests.

---

## 10. Validation status of the Phase 2 artifacts

Performed (Windows dev host):

- Shell syntax: `bash -n` on all five scripts (Git Bash) — clean.
- YAML: `docker-compose.yml` and `cloudflared/config.yml.example` parse with Symfony YAML; services/images/volumes/networks and published ports inspected.
- Ignore rules: `git check-ignore` confirms `.env`, backups, tunnel credentials and the backup marker are excluded; `.env.production.example` is committed.
- Laravel-side assumptions re-checked against source: `config/filesystems.php` (`local` disk rooted at `storage/app/private`, `serve => true` requires signed URLs for private files), upload limit `max:10240` (10 MB) vs nginx 12 MB / PHP 16 MB, `config/app.php` UTC, `health: '/up'`, `statefulApi()`, `TRUSTED_PROXIES`, JSON errors on `api/*`, `DatabaseSeeder` MySQL-only truncation, no model uses `Laravel\Scout\Searchable`.

**Not performed (blocked):**

- `docker compose config`/`build`/`up`: Docker is not installed on this Windows workstation.
- Any migration, seed, backup, restore, or verify run: no `psql`/MySQL client on the host, CLI PHP has only `pdo_sqlite`, and no backup/approval exists for the real dataset.
- Tunnel and public-HTTPS checks (`verify.sh --public`): requires the Ubuntu VM, a Cloudflare zone and the connector token.

These must be completed on the Ubuntu VM before cutover, in the order given by `deploy/selfhosted/README.md` §8–§11.
