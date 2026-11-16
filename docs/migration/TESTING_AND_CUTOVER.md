# Phase 0 — Testing & Cutover Plan

**Goal:** Migrate to Next.js (Vercel) + Laravel API (self-hosted Ubuntu VM via Cloudflare Tunnel) with zero loss of functionality, data, permissions, or visual parity. **No cutover actions performed in Phase 0.**

---

## 1. Testing strategy overview

| Level | Purpose | When |
|---|---|---|
| Source/contract audit | Confirm routes, payloads, validation, authz, side effects | Phase 0–1 |
| Unit/feature/API tests (Laravel) | Protect backend behavior and new API contracts | Phase 1 onward |
| Frontend component/integration tests | Validate UI states, forms, API integration | Phase 3 onward |
| End-to-end workflow tests | Validate real user journeys across frontend+API+DB+storage+jobs | Phase 4–6 |
| Infrastructure/recovery tests | Validate VM/tunnel/service restart, backups/restores | Phase 2, 4, 6 |
| UAT/acceptance | Sign-off on parity and production readiness | Phase 6 |

**Parity rule:** for each migrated page/feature, compare against original Blade behavior and design before replacement.

### 1.1 Phase 1 automated API test status (implemented)

**Added:** `tests/Feature/Api/V1/` (Pest, `RefreshDatabase`, SQLite in-memory, Sanitized test env from `phpunit.xml`).

| File | Coverage |
|---|---|
| `ApiAuthTest.php` | Health check; stateful Sanctum session login; invalid/deactivated credentials; login throttling/lockout; 401 unauthenticated; JSON error rendering without a JSON `Accept` header; authenticated user payload; forced-password-change enforcement and recovery; password update; profile update; notifications read-all; logout. |
| `ApiInternsTest.php` | Role-based list access; intern creation + linked account/default password; create authorization; update; soft-delete/restore; show authorization/ownership; admin-only archived filter. |
| `ApiInternDocumentsTest.php` | DTR upload + pending hours; approval credits rendered hours; missing-DTR-days validation; rejection-remarks validation; cross-intern download denial; inline preview ownership; review/folders role denial; admin/peso list + folder access. |
| `ApiSettingsAndReportsTest.php` | Settings role denial; company create/toggle/delete + in-use delete guard; document-type in-use delete guard + unchanged name/code update; user creation/default password; last-admin/self-delete protection; dashboard role payloads; self-service tabs; CSV export permissions/content; audit-log admin-only. |
| `ApiResourceContractTest.php` | Response-contract hardening: storage internals (`file_path`, `system_filename`) never serialized; whitelisted user fields only (no `password`, `email`, `remember_token`); computed intern figures match Blade accessors; DTR day breakdown/summary; notification payload passthrough; `throttle:120,1` returns 429. |
| `Settings/DocumentTypeManagementTest.php` (web) | Web document-type update without a hidden `id`; duplicate name still rejected. |

**Result (2026-11-16):** 40 API + settings tests passing (184 assertions) and 6 response-contract tests (77 assertions). Full suite: **144 passing (636 assertions), 10 failing**; the 10 failures are pre-existing and unrelated to Phase 1: Breeze-scaffolding tests for disabled routes/features (registration, password reset, email verification, and the password-confirmation test whose controller validates `email` while this app authenticates by `username`). Record this baseline before any Phase 2 changes so new regressions are distinguishable.

### 1.2 Live CSRF / session verification (implemented and passing)

PHPUnit bypasses CSRF by design, so the cookie handshake is proven separately by `scripts/verify-api-csrf.ps1`. It runs the real HTTP stack against a **throwaway SQLite database under `%TEMP%`** — it never touches the configured MySQL/PostgreSQL database — and checks:

| # | Check | Expected |
|---|---|---|
| 1 | `GET /sanctum/csrf-cookie` | issues `XSRF-TOKEN` |
| 2 | `POST /api/v1/auth/login` with the matching token | `200` + user payload |
| 3 | `GET /api/v1/auth/user` with the session cookie | `200`, same user |
| 4 | `POST` with a tampered `X-XSRF-TOKEN` | `419` JSON (`CSRF token mismatch.`) |
| 5 | `GET /api/v1/dashboard` unauthenticated with `Accept: */*` | `401` JSON |
| 6 | `POST /api/v1/auth/logout` | `200` |
| 7 | `GET /api/v1/auth/user` after logout | `401` |

**Result (2026-11-16): all 11 assertions pass** (`All CSRF/session checks passed.`).

Run it from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-api-csrf.ps1
```

**Two Windows/execution gotchas the script encodes:**

1. **Stateful headers are required.** Sanctum's `EnsureFrontendRequestsAreStateful` only boots the session/cookie middleware when the request carries an `Origin`/`Referer` matching `SANCTUM_STATEFUL_DOMAINS`. A bare `curl` GET therefore looks stateless and returns `401` even with a valid session cookie. Browsers always send these headers, so the SPA is unaffected — but API clients and probes must send them, and production `SANCTUM_STATEFUL_DOMAINS` must list the real frontend host.
2. **`php artisan serve` reload mode cannot bind on Windows.** Reload mode strips every variable not in `ServeCommand::$passthroughVariables`, including `SystemRoot`; without it PHP's built-in server fails with `Failed to listen on 127.0.0.1:<port> (reason: ?)`. The script uses `--no-reload` (full environment) and forces `PHP_CLI_SERVER_WORKERS=1` (Windows PHP has no `fork`, and `.env` sets it to `4`). This only affects local verification — production runs nginx + PHP-FPM, not `artisan serve`.

**Still needed before cutover:** browser-level session-expiry/419 behaviour in the real frontend, CORS/cookie verification across the production `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` split, complete per-page parity checks, and the queue/scheduler/backup-restore/infrastructure drills below.

---

## 2. Per-page / per-feature migration checklist

For every Blade page or workflow:
1. Capture baseline (screenshots/behavior notes, including responsive states).
2. Inventory interactions, permissions, validations, and empty/error/loading states.
3. Map backend data/mutations/jobs/notifications involved.
4. Implement/reuse Laravel API endpoint(s) with identical rules.
5. Rebuild UI in Next.js with matching layout, components, and navigation.
6. Connect to API and handle auth/session/CSRF/forced-password-change states.
7. Compare against baseline (visual + functional).
8. Execute role-based and edge-case tests.

### Priority feature areas (must-pass)
- Authentication/login/logout/session expiry, password confirm/update, **mandatory password change**
- Dashboard (all role-relevant content)
- Interns: list/view/create/edit/delete/restore/force-delete
- Intern documents: index/folders/folder, upload, preview, download, review/approve/reject, DTR day selection/viewing, trashed access behavior where applicable
- My Internship: DTR tab, 201 Files, unassigned states, hours/progress displays
- Settings: companies (CRUD/toggle), document types (CRUD), users (CRUD/reset password/toggle status)
- Reports: intern reports view and all exports (interns, hours, agencies, expiry)
- Audit log viewing/filtering as currently supported
- Notifications: list/read/read-all and navigation targets
- Profile management

---

## 3. Functional test matrix

### 3.1 Authentication & authorization
- [ ] Sanctum SPA flow: `/sanctum/csrf-cookie` → login → authenticated requests → logout
- [ ] Invalid credentials, inactive user, throttling/lockout behavior (as currently implemented)
- [ ] Session persistence across refresh/navigation; expiry/419 handling
- [ ] `must_change_password` enforcement blocks restricted actions/routes until resolved
- [ ] Role matrix verification for `admin`, `peso_manager`, `intern` (allow/deny per action and ownership)
- [ ] 401/403 responses do not leak unauthorized data

### 3.2 Data integrity & CRUD
- [ ] All create/update/delete/restore flows persist correctly and preserve relationships
- [ ] Soft-delete and restore/force-delete semantics unchanged
- [ ] Validation parity (server-side) and UI error display parity
- [ ] Audit logging recorded for sensitive actions as today

### 3.3 Documents, DTR, and storage
- [ ] Upload validation (type/size/pages/metadata/period/DTR days) matches existing rules
- [ ] Stored files remain private; no direct public URL access
- [ ] Preview/download require authorization; owner/admin/peso_manager rules enforced
- [ ] Soft-deleted document access behavior preserved where currently allowed
- [ ] DTR day records, summaries, outside-contract indicators, and hours calculations match
- [ ] Review actions update statuses/remarks/reviewer/timestamps and trigger same side effects (notifications, recalcs)

### 3.4 Reports, exports, and background jobs
- [ ] Report filters/aggregations match existing output
- [ ] Exports (interns/hours/agencies/expiry) produce correct files/content/permissions
- [ ] Long-running exports handled without request timeouts (async + status/download flow if implemented per spec)
- [ ] Queue workers process jobs reliably; failures visible/logged

### 3.5 Search
- [ ] Meilisearch-backed search returns same authorized results/filters/sorting as current behavior
- [ ] Index updates reflect document/data changes as expected
- [ ] No Meilisearch credentials or direct endpoints exposed to frontend

### 3.6 Notifications & audit
- [ ] Notifications generated for existing events and delivered/stored as today
- [ ] Read/read-all state transitions correct
- [ ] Audit log entries complete and viewable to authorized users only

---

## 4. Infrastructure & non-functional testing

### 4.1 Deployment/runtime
- [ ] Vercel frontend serves correct domain/build and uses configured `NEXT_PUBLIC_API_URL`
- [ ] Cloudflare Tunnel routes `api.example.com` to backend only; reconnects after restart/network interruption
- [ ] Ubuntu services auto-start after VM reboot (Docker, Nginx/PHP, Postgres, Meilisearch, queue, scheduler, tunnel)
- [ ] Windows host restart recovers VM and services per configuration

### 4.2 Security
- [ ] `APP_DEBUG=false`, HTTPS everywhere, secure/HttpOnly cookies, correct `SESSION_DOMAIN`/SameSite behavior
- [ ] Strict CORS (exact frontend origin, credentials enabled only where required)
- [ ] CSRF protection effective for SPA mutations
- [ ] Rate limiting/throttling on sensitive endpoints (as designed/implemented)
- [ ] No public exposure of Postgres, Meilisearch admin, private storage, or admin interfaces
- [ ] Cloudflare protections do not block legitimate Sanctum flows

### 4.3 Performance & reliability (baseline vs target)
- [ ] Key pages/APIs meet acceptable response times under representative load
- [ ] Large document/report operations complete reliably (sync/async as appropriate)
- [ ] Logs/monitoring available for app, worker, scheduler, tunnel

---

## 5. Backup, restore, and recovery drills

- [ ] Verify daily DB and document backup jobs produce readable artifacts
- [ ] **Perform test restoration to a separate database before production cutover** (mandatory)
- [ ] Validate restored data integrity and sample application workflows against restored dataset
- [ ] Validate file restore and document preview/download resolution
- [ ] Confirm off-host backup copy exists and is retrievable
- [ ] Document and test rollback procedure (DNS/app rollback, backend rollback, data considerations)

---

## 6. Cutover plan (Phase 5–6)

### 6.1 Preconditions
- All Phase 0–4 deliverables complete and reviewed.
- API parity and integration tests passing for in-scope workflows.
- Infrastructure stable (VM, services, tunnel, domains, HTTPS).
- Verified backups and successful isolated restore completed.
- Rollback plan approved and ready.

### 6.2 Cutover steps (high-level)
1. Freeze/communicate deployment window and maintenance expectations.
2. Take fresh verified backups (DB + files + config snapshots).
3. Deploy/release approved backend and frontend versions.
4. Confirm production env vars/secrets, CORS/Sanctum/session/cookie settings, and tunnel routing.
5. Run smoke tests with real role accounts (admin, peso_manager, intern) on production domains.
6. Validate critical workflows: auth, interns, uploads/downloads/previews, reviews, reports/exports, notifications, audit logs, jobs.
7. Monitor logs/errors/queue/scheduler/tunnel health.
8. Obtain acceptance sign-off, then direct users to new frontend.

### 6.3 Post-cutover
- Keep original Blade application and deployment artifacts available for rollback period.
- Monitor for regressions and performance issues; triage with priority on auth, data integrity, and document access.
- Confirm scheduled tasks and backups continue to run successfully.

### 6.4 Rollback triggers & approach
- **Triggers:** critical auth failure, data corruption/loss risk, widespread permission leaks, inability to access/upload/download required documents, or unrecoverable production outage.
- **Approach:** revert traffic/frontend to previous stable application, disable problematic new API paths if needed, restore services/config from known-good state, and if required restore data from verified backups following approved recovery procedure.

---

## 7. Acceptance criteria traceability

Cutover is approved only when all spec acceptance criteria are satisfied:
1. All existing GIMS features work
2. All roles/permissions preserved
3. Existing DB records/relationships intact
4. UI matches original design
5. Sanctum auth works on production domains
6. Private documents remain private
7. Uploads/previews/downloads work
8. Reports/exports work
9. Background jobs work
10. Meilisearch works
11. Backend auto-starts after Windows restart
12. Tunnel reconnects automatically
13. DB and file backups verified
14. Test restoration succeeds
15. Performance and security testing passed

## 8. Open items to finalize during later phases
- Exact automated test coverage targets and CI pipeline (if introduced).
- Representative production-like dataset/accounts for UAT without exposing sensitive data.
- Detailed runbooks/scripts for restart, failover, and rollback execution.
- Final RTO/RPO expectations and monitoring/alerting thresholds.
