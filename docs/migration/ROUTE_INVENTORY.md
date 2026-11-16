# Route Inventory (Phase 0 baseline + Phase 1 API)

**Source:** `php artisan route:list --except-vendor` (executed 2026-11-16), `routes/web.php`, `routes/auth.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`.  
**Total application routes:** 59 web (unchanged from Phase 0) + 54 `/api/v1` API routes. **API routes:** implemented in `routes/api.php` (see `docs/migration/API_DESIGN.md` §4.8 for the endpoint list). **Console schedule:** defined in `routes/console.php`.

---

## 1. Routing/bootstrap summary

| Item | Value | Evidence |
|---|---|---|
| Web routes file | `routes/web.php` | `bootstrap/app.php` |
| Auth routes file | `routes/auth.php` (required by `web.php`) | `routes/web.php` |
| API routes | `routes/api.php` (prefix `v1`, names `api.v1.*`) | `bootstrap/app.php` (`api:`) |
| Console routes/schedule | `routes/console.php` | `bootstrap/app.php`, schedule entries below |
| Health check | `/up` (framework) + `/api/v1/health` (API) | `bootstrap/app.php`, `routes/api.php` |
| Global web middleware additions | `ForcePasswordChange` appended to `web` | `bootstrap/app.php` |
| Global API middleware | Sanctum `statefulApi()` + `ForcePasswordChange` appended to `api`; JSON error rendering for `/api/*` | `bootstrap/app.php` |
| Middleware aliases | `role` → `App\Http\Middleware\EnsureUserHasRole` | `bootstrap/app.php` |

---

## 2. Web route inventory (grouped)

> **Legend:** Middleware shown as reported by route list plus effective group middleware from `web.php`/`bootstrap/app.php` where relevant. `web` group and appended `ForcePasswordChange` apply to web routes; `auth`/`verified`/`role:*`/`can:*` as listed.

### 2.1 Entry & dashboard

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/` | — | (closure) | Redirect to dashboard if authenticated; else render `auth.login` |
| GET|HEAD | `/dashboard` | `auth`, `verified` (route), `web` | `dashboard` | `DashboardController@index` |

### 2.2 Authentication & password (active)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/login` | guest (from `auth.php`) | `login` | `Auth\AuthenticatedSessionController@create` |
| POST | `/login` | — | guest | — | `Auth\AuthenticatedSessionController@store` |
| POST | `/logout` | `logout` | auth | — | `Auth\AuthenticatedSessionController@destroy` |
| GET|HEAD | `/confirm-password` | auth | `password.confirm` | `Auth\ConfirmablePasswordController@show` |
| POST | `/confirm-password` | — | auth | — | `Auth\ConfirmablePasswordController@store` |
| PUT | `/password` | `password.update` | auth | — | `Auth\PasswordController@update` |
| GET|HEAD | `/force-password-change` | auth | `password.force-change` | `Auth\ForcePasswordChangeController@show` |
| POST | `/force-password-change` | — | auth | `password.force-change.store` | `Auth\ForcePasswordChangeController@store` |

**Disabled/commented in `routes/auth.php`:** register, forgot-password, reset-password, email verification notice/verify/resend.

### 2.3 Profile & notifications (authenticated)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/profile` | auth | `profile.edit` | `ProfileController@edit` |
| PATCH | `/profile` | auth | `profile.update` | `ProfileController@update` |
| DELETE | `/profile` | auth | `profile.destroy` | `ProfileController@destroy` |
| GET|HEAD | `/notifications` | auth | `notifications.index` | `NotificationController@index` |
| POST | `/notifications/mark-all-read` | auth | `notifications.mark-all-read` | `NotificationController@markAllAsRead` |
| POST | `/notifications/{id}/read` | auth | `notifications.mark-as-read` | `NotificationController@markAsRead` |

### 2.4 Admin settings (`/settings/*`, `role:admin`)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/settings/companies` | auth, role:admin | `settings.companies.index` | `CompanyController@index` |
| POST | `/settings/companies` | auth, role:admin | `settings.companies.store` | `CompanyController@store` |
| GET|HEAD | `/settings/companies/create` | auth, role:admin | `settings.companies.create` | `CompanyController@create` |
| PUT|PATCH | `/settings/companies/{company}` | auth, role:admin | `settings.companies.update` | `CompanyController@update` |
| DELETE | `/settings/companies/{company}` | auth, role:admin | `settings.companies.destroy` | `CompanyController@destroy` |
| GET|HEAD | `/settings/companies/{company}/edit` | auth, role:admin | `settings.companies.edit` | `CompanyController@edit` |
| PATCH | `/settings/companies/{company}/toggle-active` | auth, role:admin | `settings.companies.toggle-active` | `CompanyController@toggleActive` |
| GET|HEAD | `/settings/document-types` | auth, role:admin | `settings.document-types.index` | `DocumentTypeController@index` |
| POST | `/settings/document-types` | auth, role:admin | `settings.document-types.store` | `DocumentTypeController@store` |
| PUT|PATCH | `/settings/document-types/{document_type}` | auth, role:admin | `settings.document-types.update` | `DocumentTypeController@update` |
| DELETE | `/settings/document-types/{document_type}` | auth, role:admin | `settings.document-types.destroy` | `DocumentTypeController@destroy` |
| GET|HEAD | `/settings/users` | auth, role:admin | `settings.users.index` | `UserController@index` |
| POST | `/settings/users` | auth, role:admin | `settings.users.store` | `UserController@store` |
| GET|HEAD | `/settings/users/create` | auth, role:admin | `settings.users.create` | `UserController@create` |
| PUT|PATCH | `/settings/users/{user}` | auth, role:admin | `settings.users.update` | `UserController@update` |
| DELETE | `/settings/users/{user}` | auth, role:admin | `settings.users.destroy` | `UserController@destroy` |
| GET|HEAD | `/settings/users/{user}/edit` | auth, role:admin | `settings.users.edit` | `UserController@edit` |
| POST | `/settings/users/{user}/reset-password` | auth, role:admin | `settings.users.reset-password` | `UserController@resetPassword` |
| PATCH | `/settings/users/{user}/toggle-status` | auth, role:admin | `settings.users.toggle-status` | `UserController@toggleStatus` |

### 2.5 Reports & audit

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/reports/audit-log` | auth, role:admin | `reports.audit-log` | `ActivityLogController@index` |
| GET|HEAD | `/reports/intern` | auth, role:admin,peso_manager | `reports.intern` | `ReportController@internIndex` |
| GET|HEAD | `/reports/export/interns` | auth, role:admin,peso_manager | `reports.export-interns` | `ReportController@exportInterns` |
| GET|HEAD | `/reports/export/intern-hours` | auth, role:admin,peso_manager | `reports.export-intern-hours` | `ReportController@exportInternHours` |
| GET|HEAD | `/reports/export/intern-agencies` | auth, role:admin,peso_manager | `reports.export-intern-agencies` | `ReportController@exportInternAgencySummary` |
| GET|HEAD | `/reports/export/intern-expiry` | auth, role:admin,peso_manager | `reports.export-intern-expiry` | `ReportController@exportInternExpiry` |

### 2.6 Interns (`/interns/*`)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/interns` | auth, `can:viewAny,App\Models\Intern` | `interns.index` | `InternController@index` |
| GET|HEAD | `/interns/create` | auth, role:admin | `interns.create` | `InternController@create` |
| POST | `/interns` | auth, role:admin | `interns.store` | `InternController@store` |
| GET|HEAD | `/interns/{intern}` | auth, `can:view,intern` | `interns.show` | `InternController@show` |
| GET|HEAD | `/interns/{intern}/edit` | auth, role:admin | `interns.edit` | `InternController@edit` |
| PUT | `/interns/{intern}` | auth, role:admin | `interns.update` | `InternController@update` |
| DELETE | `/interns/{intern}` | auth, role:admin | `interns.destroy` | `InternController@destroy` |
| PATCH | `/interns/{id}/restore` | auth, role:admin | `interns.restore` | `InternController@restore` |
| DELETE | `/interns/{id}/force-delete` | auth, role:admin | `interns.forceDelete` | `InternController@forceDelete` |

### 2.7 Intern documents (`/intern-documents/*`)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/intern-documents` | auth, `can:viewAny,App\Models\InternDocument` | `intern-documents.index` | `InternDocumentController@index` |
| POST | `/intern-documents` | auth | `intern-documents.store` | `InternDocumentController@store` (authorization enforced in controller/policy/request as applicable) |
| GET|HEAD | `/intern-documents/folders` | auth, role:admin,peso_manager | `intern-documents.folders` | `InternDocumentController@folders` |
| GET|HEAD | `/intern-documents/folders/{intern}` | auth, role:admin,peso_manager | `intern-documents.folder` | `InternDocumentController@internFolder` |
| GET|HEAD | `/intern-documents/{document}/preview` | auth, `can:download,document`, `withTrashed` | `intern-documents.preview` | `InternDocumentController@preview` |
| GET|HEAD | `/intern-documents/{document}/download` | auth, `can:download,document`, `withTrashed` | `intern-documents.download` | `InternDocumentController@download` |
| PATCH | `/intern-documents/{document}/review` | auth, role:admin,peso_manager | `intern-documents.review` | `InternDocumentController@review` |

### 2.8 Intern self-service (`/my-internship/*`, `role:intern`)

| Method | URI | Name | Middleware / guards | Action |
|---|---|---|---|---|
| GET|HEAD | `/my-internship` | auth, role:intern | `my-internship.index` | `MyInternshipController@index` (DTR tab; route name preserved) |
| GET|HEAD | `/my-internship/201-files` | auth, role:intern | `my-internship.files` | `MyInternshipController@files` |

---

## 3. Console / scheduler inventory

| Type | Command / purpose | Schedule | Evidence |
|---|---|---|---|
| Artisan command | `inspire` (default demo) | none | `routes/console.php` |
| Scheduled command | `interns:check-contract-expiry` | daily at 00:05 | `routes/console.php` |
| Scheduled command | `interns:check-completion` | daily at 00:10 | `routes/console.php` |

**Registered command classes:** `app/Console/Commands/CheckInternCompletion.php`, `CheckInternContractExpiry.php`, `RecalculateInternHours.php` (manual/on-demand availability to verify).

---

## 4. API endpoint inventory (current)

- **None.** No `/api/v1` or other API routes exist in source. Proposed mapping must be derived from the verified web routes/features above (see `API_DESIGN.md`).

---

## 5. Inventory notes for migration

- `withTrashed()` on preview/download routes indicates soft-deleted document access paths must remain authorized and functional as today.
- Several routes rely on **policy abilities** (`can:*`) while others rely on **role middleware**; API design must preserve equivalent authorization semantics.
- Route names (e.g. `my-internship.index`) have functional/linkage significance per source comments and must be considered when preserving user-facing behavior/redirects.
- Full request/response payloads, validation rules, and side effects are defined in controllers/requests/services and must be captured in feature/API audits before endpoint implementation.
