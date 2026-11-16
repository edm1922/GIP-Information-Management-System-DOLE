# Phase 0 — Project Audit (GIMS)

**Audit date:** 2026-11-16 (repository inspection)  
**Scope:** Source-level audit of `C:\xampp\htdocs\GIPS` (Laravel 12 application). No application code, schema, or production data was modified.  
**Goal:** Establish an evidence-based baseline for migrating the Blade frontend to Next.js (Vercel) while retaining Laravel 12 as the backend API on a self-hosted Ubuntu VM (Windows 11 host) behind Cloudflare Tunnel, preserving all behavior, data, roles, and UI.

---

## 1. Executive summary

- **Current app:** Monolithic Laravel 12 web app (Blade + Vite/Tailwind/Alpine) with session-based auth (Breeze-style), role-string authorization (`admin`, `peso_manager`, `intern`), PostgreSQL-oriented configuration, database-backed sessions/cache/queue, Meilisearch/Scout integration, and file/document workflows centered on interns, DTRs, and 201-files.
- **API readiness:** **No existing API layer** (`routes/api.php` absent, no Sanctum package/config, no SPA/CORS/stateful setup). Target requires `/api/v1` + Sanctum SPA cookie auth added without breaking existing web flows until cutover.
- **Data model complexity:** **74 migrations** reflect major evolution (employees/departments/201-files → intern-focused model, shared `documents` table with global scopes/categories, DTR days/hours). Preservation of tables/relationships/keys/data and document-category semantics is a primary migration risk.
- **Operations today:** Console scheduling exists for intern contract/completion checks; queue defaults to database; Scout/Meilisearch configured (including legacy model references in config to verify against runtime needs); local/private filesystem disk rooted at `storage/app/private`.
- **Deployment gap vs target:** Current `.env.example` and config are not yet aligned to split origins (`gims.example.com` frontend / `api.example.com` backend), Sanctum stateful domains, strict CORS, or production-hardened self-hosted service orchestration (Docker Compose, cloudflared, worker/scheduler supervision, backups).

---

## 2. Repository & stack inventory

| Item | Evidence | Notes |
|---|---|---|
| PHP / Laravel | `composer.json`: `php ^8.2`, `laravel/framework ^12.0` | Standard Laravel 12 app skeleton base (`laravel/laravel`) |
| Key backend deps | `laravel/scout`, `meilisearch/meilisearch-php`, `phpoffice/phpspreadsheet`, `setasign/fpdf`, `setasign/fpdi` | Reports/exports and PDF handling present; search via Scout/Meilisearch |
| Auth/UI deps (dev/build) | `laravel/breeze`, Vite, Tailwind, Alpine, axios | Blade-based UI; no Inertia/Livewire indicated by deps |
| Frontend | `package.json`: Vite 6, Tailwind 3, Alpine, `docx-preview` | `resources/views/**` Blade templates |
| App name/branding defaults | `.env.example`: `APP_NAME`, `APP_BRAND_*`, logos/colors | Must remain configurable |
| Database default (example) | `.env.example`: `DB_CONNECTION=pgsql`, `DB_SSLMODE=require` | Spec requires preserving existing PostgreSQL DB/data |
| Sessions/cache/queue (example) | `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` | DB-backed operational state |
| Search (example) | `SCOUT_DRIVER=meilisearch`, `SCOUT_QUEUE=false`, `MEILISEARCH_HOST/KEY` | Config also defines index settings (`config/scout.php`) |
| Filesystem default | `config/filesystems.php`: `local` → `storage_path('app/private')`, `public` disk separate | Private document handling must be enforced at app/authz layer |

**Notable repository artifacts:** `database/database_dump.sql`, `deploy/`, `nixpacks.toml`, `railway.json`, `graphify-out/`, large media/xlsx files in root — require review for migration relevance but **must not** drive destructive actions.

---

## 3. Application structure (inspected)

- `app/Http/Controllers/`: `DashboardController`, `InternController`, `InternDocumentController`, `MyInternshipController`, `CompanyController`, `DocumentTypeController`, `UserController`, `ReportController`, `ActivityLogController`, `NotificationController`, `ProfileController`, `Auth/*`, `Controller`.
- `app/Http/Middleware/`: `EnsureUserHasRole`, `ForcePasswordChange`.
- `app/Http/Requests/`: auth/profile/company/document-type/intern/user/review-store request objects.
- `app/Models/`: `User`, `Intern`, `InternDocument`, `DocumentType`, `Company`, `DtrDay`, `AuditLog`.
- `app/Policies/`: `InternPolicy`, `InternDocumentPolicy`.
- `app/Services/`: `AuditService`, `InternDocumentUploadService`, `InternHoursService`, `InternNotificationService`, `InternWorkCalendar`.
- `app/Notifications/`: `InternDocumentReviewed`, `InternDocumentUploaded`, `InternStatusAlert`.
- `app/Console/Commands/`: `CheckInternCompletion`, `CheckInternContractExpiry`, `RecalculateInternHours`.
- `routes/`: `web.php` (primary app routes), `auth.php`, `console.php` (no `api.php`).
- `resources/views/`: Blade UI for dashboard, interns, intern documents, my-internship, reports, settings, notifications, profile, auth, errors, layouts/components/partials.
- `database/migrations/`: extensive history including intern/DTR/document refactors and legacy removals.

---

## 4. Bootstrap, routing, and middleware

**`bootstrap/app.php`**
- Routes: `web` (`routes/web.php`), `commands` (`routes/console.php`), health `/up`. *(Phase 0 baseline: no API routing.)*
- **Phase 1 additions (post-audit, tracked in `API_DESIGN.md`):** `api:` routing via `routes/api.php` (`/api/v1`), Sanctum `statefulApi()`, `ForcePasswordChange` appended to `api`, and JSON error rendering for all `/api/*` requests.
- Middleware alias: `role` → `App\Http\Middleware\EnsureUserHasRole`.
- Trusted proxies: defaults `127.0.0.1, ::1` plus `TRUSTED_PROXIES` env (important for tunnel/reverse-proxy HTTPS and secure cookies).
- Web middleware group append: `ForcePasswordChange` (enforces password change before most routes; allows force-change routes + logout).

**`routes/console.php`**
- Scheduled tasks: `interns:check-contract-expiry` daily `00:05`, `interns:check-completion` daily `00:10`.

**`routes/auth.php`**
- Active: login, confirm password, password update, logout.
- Commented/disabled: register, forgot/reset password, email verification flows.

**`routes/web.php`**
- 59 routes (see `ROUTE_INVENTORY.md`) covering dashboard, profile, notifications, admin settings (companies/document types/users), audit log, internship reports/exports, interns CRUD+restore/force-delete, intern document upload/review/preview/download/folders, intern self-service (`my-internship`, `201-files`).

---

## 5. Authentication, authorization, and account controls

| Aspect | Current state (inspected) | Migration implication |
|---|---|---|
| Guard/provider | Session guard `web`, Eloquent `App\Models\User` (`config/auth.php`) | Preserve behavior; add Sanctum SPA without replacing business rules |
| User model | `username`-based identity fields, `role`, `must_change_password`, `is_active`, soft deletes, relations to `auditLogs` and `intern` | Preserve fields/semantics; verify login identifier and password policies in controllers/requests |
| Roles | `admin`, `peso_manager`, `intern` (`User` helpers, `EnsureUserHasRole`, policies, route middleware) | Exact role strings/permissions must be preserved |
| Policies | `InternPolicy`, `InternDocumentPolicy` (view/upload/review/download/delete rules incl. intern self-access) | API must enforce same abilities server-side |
| Forced password change | Middleware + routes/controller present | Must remain mandatory post-login behavior |
| CSRF/session | Standard Laravel web/session defaults; session config env-driven (`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, etc.) | Target requires Sanctum stateful SPA config on shared registrable domain |

**Spec gap:** Sanctum SPA (`/sanctum/csrf-cookie`, stateful domains, `statefulApi()`, strict CORS credentials) is not yet implemented.

---

## 6. Domain model & data highlights (source-derived)

- **Users:** account/profile, role, activation, forced password change, linkage to intern record.
- **Interns:** linked `user_id`, `partner_agency_id` (company), contract dates, required/rendered hours, status/type/batch/beneficiary/school/course/contact/supervisor; computed progress/hours-remaining/expiry helpers; soft deletes.
- **Companies:** `name`, `code`, `is_active`, `is_partner_agency` (default true) — used as partner agencies.
- **Document types:** `code/name`, expiry/pages flags, `is_intern_document`, `is_optional`.
- **Documents / Intern documents:** `InternDocument` uses `documents` table with global scope `intern_id IS NOT NULL`; stores file metadata, review workflow fields, period, metadata JSON, relations to DTR days/uploader/reviewer/intern/type. **Shared-table history and category separation must be preserved** (see spec and migrations).
- **DTR days:** per-day claimed work/hours linked to intern document and intern; “counted” scope excludes rejected documents.
- **Audit logs:** action/model/target/description/changes/IP/UA; custom target/description helpers.

**Scout config observation:** `config/scout.php` references `App\Models\Document` and `App\Models\Employee` index settings, but corresponding model classes are not present under `app/Models` in this snapshot. Actual searchable models/index usage must be verified against controllers/models and runtime DB/index state during implementation.

---

## 7. Search, queue, scheduler, and background processing

| Component | Current evidence | Notes |
|---|---|---|
| Search | Scout + Meilisearch deps/config; `SCOUT_QUEUE=false` in example | Do not expose Meilisearch publicly; verify indexed models/attributes/filters/auth in detail |
| Queue | Database queue default; jobs/batches/failed tables configured | Workers must run on self-hosted Ubuntu in target; identify long-running report/export/upload tasks for async handling where needed |
| Scheduler | Two daily intern commands scheduled in `routes/console.php` | Requires reliable scheduler execution (cron/systemd timer/container) in target |
| Commands | Contract expiry, completion, hours recalculation | Preserve behavior and notification side effects |

---

## 8. File storage and document security (current)

- Default disk: `local` (`storage/app/private`, `serve => true` in config). Public disk exists separately (`storage/app/public`, symlink mapping configured).
- Document endpoints include preview/download with authorization checks and `withTrashed` support on routes (intern documents). Upload/review flows implemented in controllers/services/requests.
- **Target requirement:** keep private GIMS docs outside public web root, enforce Laravel authorization on all document endpoints, preserve metadata/DB refs/ownership/review workflows, ensure persistence across restarts/updates, no public directory exposure.

---

## 9. Frontend/UI baseline (Blade)

- Layouts/navigation/sidebar/app/guest shells; reusable components under `resources/views/components`.
- Feature UIs: dashboard; interns list/show/create/edit + forms/modals; intern document index/folder browser/per-intern folder, upload/review/reject/DTR picker/trigger/viewer partials; intern self-service dashboard, 201-files, hours card, documents table, unassigned state; reports (intern, audit log); settings (companies, document types, users); notifications; profile; auth (login, confirm password, force password change); custom error pages.
- **Migration constraint:** rebuild in Next.js (App Router, TS) with visual/behavioral parity, preserving user-facing paths where practical.

---

## 10. Configuration & environment (current vs target)

**Present/configured in repo examples/config:** `APP_*`/branding, DB/pgsql SSL, session/cache/queue database, Scout/Meilisearch, mail log, trusted proxies, filesystem local/public/S3 scaffolding.  
**Not present:** `config/cors.php`, `config/sanctum.php`, API routes, Vercel/frontend env contract, Cloudflare/Tunnel/Compose artifacts for this target.  
**Target additions (planned, not implemented):** `APP_URL=https://api.example.com`, `SESSION_DOMAIN=.example.com`, `SANCTUM_STATEFUL_DOMAINS=gims.example.com`, `SESSION_SECURE_COOKIE=true`, strict `CORS` allowed origin(s), `NEXT_PUBLIC_API_URL` (frontend-only), queue/scheduler/runtime service envs, backup envs — all env-driven and secret-safe.

---

## 11. Gaps, risks, and verification items

| Severity | Item | Why it matters |
|---|---|---|
| High | No Sanctum/API/CORS foundation | Required for Next.js on Vercel + Laravel API on separate host with cookie auth |
| High | Shared `documents` table & global scopes/categories | Risk of breaking intern vs legacy/department document behavior, previews/downloads, DTR linkage |
| High | Existing DB/data/relationships unknown at runtime in this audit | Must backup, inventory live schema/FKs/indexes/triggers/migration status before changes |
| High | Private file storage & authorization completeness | Any API conversion must preserve strict access controls and non-public storage |
| Medium | Scout config references models not present in `app/Models` | Potential stale config or runtime mismatch; verify before relying on search in migration |
| Medium | Report/export execution model | Large exports may need async jobs + status polling per spec |
| Medium | Commented/disabled auth flows (register/reset/verify) | Clarify intended supported workflows; avoid accidental behavior changes |
| Medium | Legacy artifacts (`database_dump.sql`, deployment stubs) | May be informative but not authoritative for target architecture |
| Low | `README.md` still generic Laravel template | Documentation debt; migration docs will supersede for this effort |

**Verification required before implementation phases:** run/inspect on actual environment — `php artisan migrate:status`, live DB schema/FK/index inventory, `route:list` in target env, auth guards/providers in use, filesystem disk paths/permissions, Scout indexes/models, queue/scheduler runtime behavior, and full role/account workflow tests.

---

## 12. Phase 0 conclusion

Phase 0 source audit is complete enough to produce the required migration documents and proceed to approved design/implementation planning. **No architectural, code, or database changes were made.** Next step: maintain this baseline, complete the companion Phase 0 artifacts in `docs/migration/`, then obtain approval before Phase 1 (API preparation) and any infra/schema-affecting work.
