# API Design (Phase 0 baseline + Phase 1 implementation status)

**Status:** Phase 1 API foundation/endpoints have been implemented in-repo alongside existing Blade/web routes (no Blade route removal). This remains a migration-in-progress document and must be validated against live behavior/tests before frontend cutover.  
**Constraint:** Map to verified existing functionality and preserve all business rules, validation, authorization, and data semantics. Do not invent features. Endpoint contracts below reflect current implementation and must be kept in sync as parity gaps are closed.

## Phase 1 implementation snapshot (2026-11-16)
- **Implemented:** `routes/api.php` (`/api/v1`, `api.v1.*`), API controllers under `app/Http/Controllers/Api/V1/*`, Sanctum SPA bootstrap (`config/sanctum.php`, `config/cors.php`, env scaffolding in `.env.example`), `statefulApi()` + API `ForcePasswordChange` middleware in `bootstrap/app.php`.
- **Auth:** SPA/session-cookie login reuses `App\Http\Requests\Auth\LoginRequest`; logout invalidates session; forced password change enforced server-side (API returns JSON `403` with `code: PASSWORD_CHANGE_REQUIRED` for blocked requests).
- **DB state:** Sanctum `personal_access_tokens` migration was published but **not run** (no destructive/unapproved DB changes). Session-based SPA auth is the implemented flow.
- **Coverage:** 54 API routes currently registered (see inventory below). Blade/web routes remain operational.
- **Rate limiting:** the whole `/api/v1` group carries `throttle:120,1` (120 requests/minute per user/IP). Login keeps its own stricter lockout (5 attempts) from `LoginRequest`.
- **Response shaping:** every payload is produced by a dedicated `App\Http\Resources\V1\*` class (`User`, `Intern`, `InternDocument`, `Company`, `DocumentType`, `DtrDay`, `Notification`, `AuditLog`). Storage internals (`file_path`, `system_filename`) are never serialized; documents expose only `preview_url`/`download_url`. Computed Blade parity figures (`progress_percent`, `hours_remaining`, `days_remaining`, `contract_months`, `dtr_summary`, …) are computed server-side so the SPA never re-implements business rules. Conditional fields (`dtr_summary`, counts, relations) are only resolved when the controller eager-loaded the data, so list endpoints cannot silently regress into N+1 queries.
- **Tests:** Phase 1 API suite (`tests/Feature/Api/V1/*`, 38 tests / 178 assertions) plus `ApiResourceContractTest.php` (6 tests / 77 assertions) and 2 web settings regression tests — all passing. Full Laravel suite: **144 passing (636 assertions), 10 failing**; the same 10 pre-existing Breeze-scaffolding failures remain (registration/password reset/email verification/password confirmation routes are disabled in this app).
- **Error rendering:** `/api/*` requests always receive JSON errors regardless of the `Accept` header (`Exceptions::shouldRenderJsonWhen` in `bootstrap/app.php`), so unauthenticated/CSRF/session failures never redirect the SPA to the Blade login page.
- **Live CSRF/session proof:** `scripts/verify-api-csrf.ps1` passes end-to-end (cookie issuance, stateful login, session auth, tampered-token `419`, unauthenticated JSON `401`, logout invalidation) against a throwaway SQLite DB. See `TESTING_AND_CUTOVER.md` §1.2.
- **Known follow-ups/gaps:** password confirmation endpoint parity not exposed in API (dormant web feature); async export/job-status endpoints not implemented (exports are synchronous like web); browser-level session-expiry UX and production CORS/domain verification happen at cutover.

---

## 1. Design principles

- **Laravel as source of truth:** business logic, validation, authorization, persistence, file handling, audit logging, and calculations remain in Laravel.
- **Versioned API:** prefix all endpoints with `/api/v1` (internal mapping to existing application actions/services).
- **Parity first:** every existing user-visible workflow must have an API equivalent or explicitly documented compatibility handling before Blade cutover.
- **Security:** Sanctum SPA cookie auth, strict CORS, CSRF, HTTPS-only cookies in production, server-side authorization on every request, no public DB/Meilisearch/storage exposure.
- **No duplicate logic:** Next.js may handle presentation/routing only; do not reimplement domain rules client-side.

---

## 2. Authentication & session (target: Sanctum SPA)

### 2.1 Chosen mechanism
- **Laravel Sanctum SPA authentication** using session cookies (not permanent Bearer tokens in browser storage).
- Frontend and API on same registrable domain (e.g. `gims.example.com` / `api.example.com`) to support first-party cookie auth.

### 2.2 Backend configuration (implemented baseline)
- Sanctum installed/configured for SPA stateful auth; `bootstrap/app.php` enables `statefulApi()` and appends `App\Http\Middleware\ForcePasswordChange` to `web` and `api`.
- Config is env-driven (no hardcoded production domains): `FRONTEND_URL`/`CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_HTTP_ONLY`, `TRUSTED_PROXIES`.
- **CORS:** `config/cors.php` allows configured frontend origin(s) and supports credentials for `api/*` and `sanctum/csrf-cookie`; production must set explicit origins (no wildcard with credentials).
- **CSRF:** SPA should call Sanctum default `GET /sanctum/csrf-cookie` before state-changing requests and send the CSRF token per Laravel/Sanctum conventions with credentialed requests.
- **Stateful detection:** Sanctum only boots the session/cookie/CSRF middleware when the request's `Origin`/`Referer` matches `SANCTUM_STATEFUL_DOMAINS`. Browsers send these automatically for SPA requests; **production must list the actual frontend host** (never `*`), or authenticated calls silently degrade to `401`. Covered by `scripts/verify-api-csrf.ps1`.
- **Trusted proxies:** loopback trusted by default; extend via `TRUSTED_PROXIES` for Cloudflare Tunnel/reverse proxy correctness.

### 2.3 Client auth flow (frontend contract)
1. `GET /sanctum/csrf-cookie`
2. `POST /api/v1/auth/login` (credentials; preserve username/password and forced-change semantics)
3. `GET /api/v1/auth/user` (or equivalent) to hydrate session/user/roles/permissions context
4. Authenticated requests with `credentials: include` and CSRF token
5. `POST /api/v1/auth/logout`
6. Handle `401/419/403` globally (session expiry, CSRF mismatch, authorization failure) and enforce mandatory password change redirect/state from API

### 2.4 Account/security endpoints (implemented + gaps)
| Endpoint | Methods | Maps to existing behavior | Status / notes |
|---|---|---|---|
| `/sanctum/csrf-cookie` | GET | Sanctum | **Implemented (Sanctum default).** Use before login/state-changing requests. |
| `/api/v1/auth/login` | POST | `AuthenticatedSessionController@store` + `LoginRequest` | **Implemented.** Session regen, `last_active_at` update, login audit log. |
| `/api/v1/auth/logout` | POST | `AuthenticatedSessionController@destroy` | **Implemented.** Logout audit log, session invalidate/regenerate token. |
| `/api/v1/auth/user` | GET | session user retrieval | **Implemented.** Returns role/profile flags and `intern_id`; excluded from forced-password redirect block. |
| `/api/v1/auth/password` | PUT | `PasswordController@update` | **Implemented.** Uses `current_password` + password rules and audit log. |
| `/api/v1/auth/force-password-change` | GET, POST | `ForcePasswordChangeController` | **Implemented.** Server-enforced; blocked API requests receive JSON 403 `PASSWORD_CHANGE_REQUIRED`. |
| `/api/v1/auth/confirm-password` | GET/POST | `ConfirmablePasswordController` | **Gap (not yet exposed).** Assess whether any SPA flow requires password confirmation parity. |

---

## 3. API conventions (implemented)

- **Content:** JSON request/response for standard endpoints; `multipart/form-data` for uploads; streamed/binary responses for authorized downloads/exports/previews as appropriate.
- **Validation errors:** Laravel standard `422` with `{ message, errors: { field: [..] } }`, produced by the existing `FormRequest`/`validate()` rules reused by API controllers.
- **Auth/errors:** `401` unauthenticated, `403` forbidden (including forced-password-change `403` with `code: PASSWORD_CHANGE_REQUIRED`), `404` not found, `419` CSRF/session mismatch, `429` throttling. Business-rule rejections currently use `422` with a top-level `message` (matching web flash-error semantics) unless a dedicated validation error is applicable. Login lockout surfaces as a `422` validation error on `username` (inherited from `LoginRequest`).
- **JSON guarantee:** every `/api/*` request renders errors as JSON even without `Accept: application/json` (`Exceptions::shouldRenderJsonWhen`), so the SPA never follows an auth redirect to the Blade login page.
- **Rate limiting:** the `/api/v1` group uses `throttle:120,1`. `429` responses are JSON; login has its own 5-attempt lockout via `LoginRequest` and returns `422` with `errors.username`.
- **Pagination/filter/sort:** list endpoints return Laravel paginator JSON (default page sizes preserved from web: interns 15, notifications 20, documents 20, folders 24, users 10, audit logs 20) and accept the same query filters as web controllers.
- **Resource shaping:** dedicated `App\Http\Resources\V1\*` classes define every payload. Storage internals (`file_path`, `system_filename`) are never serialized; files are reachable only via authorized `preview_url`/`download_url`. Blade-parity computed values are server-side. Conditional fields are gated on eager-loaded relations (`whenLoaded`, `relationLoaded`) to prevent N+1 regressions.
- **Dates/times/numbers:** dates use `Y-m-d` for calendar/business dates, ISO-8601 for audited timestamps, and floats for rendered/per-day hours to preserve the existing DTR precision semantics.
- **Uploads:** JSON response includes created document payload + `self_service_route` hint (web route name) for parity; SPA should prefer its own routing map.
- **Route binding:** API and web both use `{document_type}`; `DocumentTypeRequest` resolves that binding (and keeps the legacy posted `id` fallback), so updates no longer depend on a hidden `id` field.

---

## 4. Implemented resource/feature endpoint mapping (current)

> Implemented routes mirror existing controllers/requests/services/policies where applicable. Form-only web routes (`create`, `edit`) are intentionally omitted for SPA JSON use; data/options are provided via list/show endpoints. All listed routes are under `/api/v1` and `auth` unless noted. Register/verification/reset scaffolding remains disabled per existing web routes.

### 4.1 Dashboard & profile
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/dashboard` | GET | `DashboardController@index` | **Implemented (`auth`).** Web uses `auth,verified`, but `User` does not implement `MustVerifyEmail`, so `verified` is a no-op and API behavior matches. |
| `/api/v1/profile` | GET, PATCH, DELETE | `ProfileController@edit/update/destroy` | **Implemented (`auth`, self).** Uses `ProfileUpdateRequest`, audit logging, session invalidation on delete. |

### 4.2 Notifications
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/notifications` | GET | `NotificationController@index` | **Implemented (`auth`).** Paginated JSON. |
| `/api/v1/notifications/{id}/read` | POST | `markAsRead` | **Implemented (`auth` + ownership).** Logs read action and returns resolved target URL when available. |
| `/api/v1/notifications/mark-all-read` | POST | `markAllAsRead` | **Implemented (`auth`).** Returns affected count and logs when applicable. |

### 4.3 Interns
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/interns` | GET | `InternController@index` | **Implemented (`can:viewAny,Intern`).** Filters: status, partner_agency_id, expiring, search, archived (admin). |
| `/api/v1/interns` | POST | `InternController@store` | **Implemented (`role:admin`).** Reuses `StoreInternRequest`, transaction/account creation logic, audit log. |
| `/api/v1/interns/agencies` | GET | form dependency (active partner agencies) | **Implemented (`can:viewAny,Intern`).** Supports SPA create/edit forms. |
| `/api/v1/interns/{intern}` | GET | `InternController@show` | **Implemented (`can:view,intern`).** Loads user, partner agency, docs/types/reviewer/dtrDays. |
| `/api/v1/interns/{intern}` | PUT | `InternController@update` | **Implemented (`role:admin`).** Reuses `UpdateInternRequest`, transaction, audit log. |
| `/api/v1/interns/{intern}` | DELETE | `InternController@destroy` | **Implemented (`role:admin`).** Soft delete + deactivates linked user + audit log. |
| `/api/v1/interns/{id}/restore` | PATCH | `InternController@restore` | **Implemented (`role:admin`).** Restores intern, reactivates linked user, audit log. |
| `/api/v1/interns/{id}/force-delete` | DELETE | `InternController@forceDelete` | **Implemented (`role:admin`).** Mirrors web behavior (archive intern documents, force delete intern, soft-delete/deactivate user, audit log). |

### 4.4 Intern documents (shared `documents` table)
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/intern-documents` | GET | `InternDocumentController@index` | **Implemented (`can:viewAny,InternDocument`).** Filters: `review_status`, `intern_id`, `q`. |
| `/api/v1/intern-documents` | POST (multipart) | `store` + `InternDocumentUploadService` | **Implemented (`auth`, policy/request-controlled).** Reuses `StoreInternDocumentRequest`, upload service, PESO-team notification, DTR/hours side effects via service logic. |
| `/api/v1/intern-documents/folders` | GET | `folders` | **Implemented (`role:admin,peso_manager`).** Search/filter + per-intern counts. |
| `/api/v1/intern-documents/folders/{intern}` | GET | `internFolder` | **Implemented (`role:admin,peso_manager`).** Splits DTR vs 201 files. |
| `/api/v1/intern-documents/{document}/preview` | GET (stream/inline) | `preview` | **Implemented (`can:download,document`, `withTrashed`).** Private storage stream with inline disposition. |
| `/api/v1/intern-documents/{document}/download` | GET (attachment) | `download` | **Implemented (`can:download,document`, `withTrashed`).** Private storage download. |
| `/api/v1/intern-documents/{document}/review` | PATCH | `review` | **Implemented (`role:admin,peso_manager`).** Reuses `ReviewInternDocumentRequest`, hours recalculation, intern notification, audit log. |

**Preserved in implementation:** `withTrashed` preview/download, `StoreInternDocumentRequest`/`ReviewInternDocumentRequest` validation (including DTR day rules), `InternDocumentUploadService` and `InternHoursService` reuse, storage via private `local` disk.  
**Gap:** no separate metadata-only `GET /intern-documents/{document}` endpoint yet; confirm if SPA needs it beyond list/show payloads.

### 4.5 Intern self-service
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/my-internship` | GET | `MyInternshipController@index` | **Implemented (`role:intern`).** Returns DTR-tab payload, taken days, document types, docs. |
| `/api/v1/my-internship/201-files` | GET | `MyInternshipController@files` | **Implemented (`role:intern`).** Returns 201-files payload including checklist/status summary. |

### 4.6 Settings
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/settings/companies` | GET, POST | `CompanyController@index/store` | **Implemented (`role:admin`).** |
| `/api/v1/settings/companies/{company}` | GET, PUT, PATCH, DELETE | show/update/destroy | **Implemented (`role:admin`).** |
| `/api/v1/settings/companies/{company}/toggle-active` | PATCH | `toggleActive` | **Implemented (`role:admin`).** |
| `/api/v1/settings/document-types` | GET, POST | `DocumentTypeController@index/store` | **Implemented (`role:admin`).** |
| `/api/v1/settings/document-types/{document_type}` | PUT, PATCH, DELETE | `update/destroy` | **Implemented (`role:admin`).** |
| `/api/v1/settings/users` | GET, POST | `UserController@index/store` | **Implemented (`role:admin`).** Store/reset responses include generated default password for parity with admin workflow. |
| `/api/v1/settings/users/{user}` | GET, PUT, PATCH, DELETE | show/update/destroy | **Implemented (`role:admin`).** Preserves self-protection/last-admin safeguards and audit logs. |
| `/api/v1/settings/users/{user}/reset-password` | POST | `resetPassword` | **Implemented (`role:admin`).** |
| `/api/v1/settings/users/{user}/toggle-status` | PATCH | `toggleStatus` | **Implemented (`role:admin`).** |

### 4.7 Reports, exports, audit
| Endpoint | Methods | Existing source | AuthZ / status |
|---|---|---|---|
| `/api/v1/reports/intern` | GET | `ReportController@internIndex` | **Implemented (`role:admin,peso_manager`).** Returns agency/filter options data. |
| `/api/v1/reports/export/interns` | GET | `exportInterns` | **Implemented (sync CSV stream, authorized).** |
| `/api/v1/reports/export/intern-hours` | GET | `exportInternHours` | **Implemented (sync CSV stream, authorized).** |
| `/api/v1/reports/export/intern-agencies` | GET | `exportInternAgencySummary` | **Implemented (sync CSV stream, authorized).** |
| `/api/v1/reports/export/intern-expiry` | GET | `exportInternExpiry` | **Implemented (sync CSV stream, authorized).** |
| `/api/v1/reports/audit-log` | GET | `ActivityLogController@index` | **Implemented (`role:admin`).** Paginated logs with user/model relations and filter inputs. |

**Gap (spec-driven):** exports are currently synchronous to match existing behavior; async job dispatch + status/download endpoints should be added if runtime/scale requires.

### 4.8 Route inventory (registered as of 2026-11-16)
`php artisan route:list --path=api/v1` reports **54 routes**:

- **Health:** `GET /api/v1/health`
- **Auth/account:** `POST /auth/login`, `POST /auth/logout`, `GET /auth/user`, `PUT /auth/password`, `GET|POST /auth/force-password-change`
- **Dashboard:** `GET /dashboard`
- **Profile:** `GET /profile`, `PATCH /profile`, `DELETE /profile`
- **Notifications:** `GET /notifications`, `POST /notifications/mark-all-read`, `POST /notifications/{id}/read`
- **Interns:** `GET /interns`, `POST /interns`, `GET /interns/agencies`, `GET /interns/{intern}`, `PUT /interns/{intern}`, `DELETE /interns/{intern}`, `PATCH /interns/{id}/restore`, `DELETE /interns/{id}/force-delete`
- **Intern documents:** `GET /intern-documents`, `POST /intern-documents`, `GET /intern-documents/folders`, `GET /intern-documents/folders/{intern}`, `GET /intern-documents/{document}/preview`, `GET /intern-documents/{document}/download`, `PATCH /intern-documents/{document}/review`
- **Intern self-service:** `GET /my-internship`, `GET /my-internship/201-files`
- **Settings (admin):** companies (index/store/show/update/destroy/toggle-active + PATCH alias), document types (index/store/update/destroy + PATCH alias), users (index/store/show/update/destroy/reset-password/toggle-status + PATCH alias)
- **Reports:** `GET /reports/intern`, `GET /reports/export/interns`, `GET /reports/export/intern-hours`, `GET /reports/export/intern-agencies`, `GET /reports/export/intern-expiry`, `GET /reports/audit-log`

> Resource updates accept both `PUT` and `PATCH` through a single `Route::match(['put','patch'], ...)` registration per resource, matching the web resource routes' verb support without duplicate route names.

---

## 5. Files, search, and background jobs

- **Uploads/downloads/previews:** remain Laravel-mediated and authorized; store under private persistent storage; never expose raw storage paths/URLs publicly. Consider short-lived signed download responses only if they preserve authorization guarantees.
- **Search:** preserve Scout/Meilisearch behavior server-side; frontend queries go to Laravel API endpoints that apply authorization and return permitted results only. Never expose Meilisearch keys/endpoints to browser/Vercel.
- **Queues/scheduler:** preserve existing scheduled commands and queued behaviors; provide job status API for any async UI flows introduced for parity/performance.

---

## 6. Frontend integration notes (Next.js)

- Use `NEXT_PUBLIC_API_URL` for API base URL; no secrets in `NEXT_PUBLIC_*`.
- Centralize API client with credentialed requests, CSRF handling, 401/419 recovery, and forced-password-change interception.
- Server Components/Route Handlers in Next.js must not become a second business-logic or authorization layer for protected GIMS operations.
- Preserve user-facing paths where practical; map page data needs to the above endpoints during per-page migration.

---

## 7. Implementation sequencing (current status)

1. ✅ **Completed:** API auth/session foundation, CORS/Sanctum config, versioned route map, endpoint implementations reusing existing requests/services/policies.
2. ✅ **Completed:** Forced-password-change is enforced server-side for API requests.
3. ✅ **Completed:** API feature tests covering stateful login/logout, 401/403/422 handling, forced password change, login throttling, JSON error rendering, intern CRUD/archive/restore, DTR upload/approval hours accounting, document preview/download ownership, settings guards/document-type updates, dashboard role payloads, self-service tabs, CSV exports, audit-log access.
4. ✅ **Completed:** Explicit `App\Http\Resources\V1\*` contract (storage internals hidden, computed Blade parity fields, conditional eager-load-gated fields), group rate limiting (`throttle:120,1`), and live CSRF/session/419 verification via `scripts/verify-api-csrf.ps1`.
5. ⏳ **Pending:** Browser-level session-expiry/419 UX in the real frontend plus per-page UI parity checks (Phase 2+).
6. ⏳ **Pending (approval-gated):** DB/config steps such as running the Sanctum `personal_access_tokens` migration only after verified backup and explicit approval.
7. 🔒 **Unchanged:** Blade/web routes remain operational until acceptance and cutover.

## 8. Open design decisions / gaps to resolve
- Exact async export/job-status contract based on current report runtime characteristics (currently synchronous).
- Password-confirmation endpoint parity (`ConfirmablePasswordController`) if required by SPA flows.
- ~~Whether `/api/v1/dashboard` should match web `verified` middleware~~ — confirmed no-op (`User` does not implement `MustVerifyEmail`); no API change needed.
- Pagination/filter query schema standardization without changing user-visible results.
- Any compatibility endpoints needed for existing notification deep-links/route names (current API passes raw `url_route`/`url_params` hints and resolves a `target_url` on mark-as-read).
- Metadata-only intern-document endpoint, if needed by SPA detail views.
- ~~Response-shape standardization~~ — resolved: dedicated V1 resources are now the contract (`ApiResourceContractTest.php` locks it in).
