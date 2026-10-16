# GIMS SCFO – GIP Information System: Implementation Plan

> **System**: Laravel 12 · PHP 8.2 · **Database**: MySQL / MariaDB (XAMPP) · **Base project**: `201files`
> **Local URL**: `http://localhost:200` · **Seed admin**: `admin` / `admincsc`
> **Last updated**: 2026-10-15

> **Corrections applied vs. the original draft** (verified against the codebase):
> 1. Database is **MySQL/MariaDB**, not PostgreSQL — use Laravel migrations (`$table->id()`, `foreignId()`), never `BIGSERIAL`.
> 2. Intern files live on the `local` disk whose root is `storage/app/private` → path is `storage/app/private/intern-docs/...` via `Storage::disk('local')`.
> 3. Runtime UI is **Bootstrap 5 + Alpine.js + `public/css/style.css` + Chart.js** (Vite/Tailwind are build-only and effectively unused at runtime).
> 4. `UserRequest.php` hard-codes the role whitelist and **will reject** new roles unless extended.
> 5. Upload processing is **synchronous** (no queue worker guaranteed; `QUEUE_CONNECTION=database`).
> 6. `documents.department_id` is `NOT NULL` (restrict) and must be relaxed to **nullable** to reuse the table.
> 7. `ForcePasswordChange` middleware exists but is **not registered** — must be wired up.

---

## 🔍 Current System Snapshot

| Aspect | Current State |
|---|---|
| Framework | Laravel 12 (`laravel/framework ^12`), PHP ^8.2 |
| Frontend | Blade + Bootstrap 5 + Alpine.js + Chart.js (`public/js/dashboard.js`), `public/css/style.css` |
| Database | MySQL/MariaDB `csc_dms` (`.env` `DB_CONNECTION=mysql`) |
| Existing Roles | `admin`, `encoder`, `viewer` — plain string on `users.role` |
| Role Middleware | `EnsureUserHasRole` (`role:` alias, comma-separated) |
| Auth | Username/password · `must_change_password` flag (**middleware not yet registered**) |
| Core Entities | User, Company, Department, Employee, Document, DocumentType, DocumentFolder, FolderLocation, AuditLog, HiringEvent |
| Documents | Stored on `local` disk under `documents/departments/{department_id}/...`; department-scoped |
| Dashboard | Hiring trends, company distribution, bank distribution, calendar (Chart.js) |

---

## 👥 Role Restructure

> Existing `encoder` / `viewer` roles are **kept** for the 201-Files / department document module. GIMS adds two new roles.

### Role Values (extend existing `users.role` string column — no breaking changes)

```
'admin'         → keep (existing)   Full CRUD, users, settings, audit, all reports
'encoder'       → keep (existing, 201-Files)
'viewer'        → keep (existing, 201-Files)
'peso_manager'  → NEW               Intern oversight; no user mgmt / settings / audit
'intern'        → NEW               Self-service only
```

> `users.role` is `string(20)`; `peso_manager` (12 chars) fits.

### Role Capability Summary

| Role | Privileges |
|---|---|
| **Admin** | Full CRUD on all GIMS & 201-Files entities; manage users; system settings; all reports & audit logs |
| **PESO Manager** | View all interns & documents; approve/reject AR & DTR; monitor hours & contracts; dashboard & reports; **no** user management, settings, or audit logs |
| **Intern** | Self-service: view own profile; upload own documents; view own hours progress & contract dates |

---

## 📋 Feature Breakdown

---

### Feature 1 – Monitoring of Hours (Hours-based)

**Decision: Hours are entered manually on the DTR upload form, then summed synchronously. No OCR, no queued job.**

#### How it works
1. Intern uploads a DTR for a period (e.g. "Week 1", "October 2026") and enters the **hours rendered** for that period.
2. The upload service updates the intern's running total in the **same DB transaction** (no `ProcessInternDtr` job).
3. A progress bar shows **`X of Y hours rendered`** (`rendered_hours / required_hours`).
4. A badge appears at ≥ 90% completion.

#### Fields
```sql
required_hours   INT UNSIGNED NOT NULL DEFAULT 486  -- per-intern, editable
rendered_hours   INT UNSIGNED NOT NULL DEFAULT 0    -- incremented on each DTR upload
```

#### UI
- Progress bar on the intern self-service page.
- Admin / PESO see rendered hours in the intern list.
- Completion badge at ≥ 90% of `required_hours`.

---

### Feature 2 – Contract Dates

#### Fields
```sql
contract_start_date  DATE NULL
contract_end_date    DATE NULL
```

#### Behavior
- Admin sets contract start & end dates when creating/editing the intern.
- System computes **days remaining** = `contract_end_date − TODAY`.
- Color-coded badges: 🟢 **Active** (> 7 days left) · 🟡 **Expiring Soon** (≤ 7 days) · 🔴 **Expired** (past end date).
- **`CheckInternContractExpiry` command runs daily** and flips `status` to `inactive` when `contract_end_date` has passed (stored & queryable, not just derived).

#### Dashboard widget (Admin & PESO)
- Table: interns whose contracts expire **within 30 days**, sorted ascending.
- Count card: "X contracts expiring this month."

---

### Feature 3 – Document Uploads

#### Document Types (seed into `document_types`; `department_id = NULL`)

| Document | Code | Required? | Uploaded By |
|---|---|---|---|
| Accomplishment Report (AR) | `AR` | ✅ Required | Intern |
| Daily Time Record (DTR) | `DTR` | ✅ Required | Intern |
| Resignation Letter | `RESIGN` | ⚠️ Optional | Intern |
| Certificate(s) | `CERT` | ⚠️ Optional | Intern |

> `document_types.code` is globally unique; these rows use `is_intern_document = true` and `department_id = NULL` (the column is already nullable). `is_required` was removed from this table in an earlier migration, so required/optional is tracked via the new `is_optional` boolean.

#### Upload Flow
```
Intern logs in → redirected to /my-internship
  → "My Documents" section
    → Select document type (AR / DTR / optional)
    → Select period ("Week 1", "October 2026")
    → Enter hours rendered (DTR only → summed synchronously)
    → Upload file (PDF / JPG / PNG, max 10 MB)
    → Stored via Storage::disk('local') → storage/app/private/intern-docs/{intern_id}/...
    → documents row created (department_id = NULL, intern_id set, review_status = 'pending')
    → Notification to PESO Manager(s) + Admin

PESO Manager / Admin
  → Reviews file → approve / reject (+ remarks)
  → review_status / reviewed_by / reviewed_at / review_remarks updated
  → Intern notified of decision
```

#### File Validation Rules
```php
'file' => 'required|mimes:pdf,jpg,jpeg,png|max:10240'
```

#### Storage Path
```
storage/app/private/intern-docs/{intern_id}/{document_type_code}/{system_filename}
```
(accessed through `Storage::disk('local')` — files are private, served via role-aware download/preview routes)

---

### Feature 4 – Dashboard

#### Role-Aware Widgets

| Widget | Admin | PESO Manager | Intern |
|---|---|---|---|
| Total No. of Interns | ✅ | ✅ | ❌ |
| Active vs. Inactive Interns | ✅ | ✅ | ❌ |
| Partner Agency Distribution | ✅ | ✅ | ❌ |
| Contracts Expiring Soon | ✅ | ✅ | ❌ |
| Interns by Month (trend) | ✅ | ✅ | ❌ |
| My Hours Progress | ❌ | ❌ | ✅ |
| My Contract Dates | ❌ | ❌ | ✅ |
| My Pending / Approved / Rejected Documents | ❌ | ❌ | ✅ |

> Interns are **redirected to `/my-internship` after login** (not the shared dashboard).

#### Chart Specs

| Chart | Type | Source |
|---|---|---|
| No. of Interns per Month | Line / Bar | `interns.created_at` grouped by month |
| Active vs. Inactive | Donut | `interns.status` |
| Partner Agency Distribution | Pie | `interns.partner_agency_id` → `companies.name` |
| Contract Expiry Timeline | Horizontal Bar | `interns.contract_end_date` grouped by month |

> Reuse the existing **Chart.js** setup (`public/js/dashboard.js`).

---

## 🗄️ Database Schema (Laravel migrations, MySQL)

### Migration: `create_interns_table`

No `employee_id` — interns are a distinct population, decoupled from the 201-Files `employees` table. Links to a `users` row (optional login) and a `companies` row (partner agency).

```php
Schema::create('interns', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('partner_agency_id')->nullable()->constrained('companies')->nullOnDelete();
    $table->foreignId('host_department_id')->nullable()->constrained('departments')->nullOnDelete();

    // Contract
    $table->date('contract_start_date')->nullable();
    $table->date('contract_end_date')->nullable();

    // Hour monitoring
    $table->unsignedInteger('required_hours')->default(486);
    $table->unsignedInteger('rendered_hours')->default(0);

    // Classification
    $table->string('status', 20)->default('active'); // active | inactive | completed
    $table->string('intern_type', 50)->nullable();   // GIP | OJT | SPES | Work Immersion
    $table->string('batch', 50)->nullable();

    // Profile
    $table->string('school')->nullable();
    $table->string('course')->nullable();
    $table->string('contact_number')->nullable();
    $table->string('supervisor')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index('status');
    $table->index('contract_end_date');
    $table->index('partner_agency_id');
});
```

> Intern **name** comes from the linked `users` row (`first_name`, `middle_name`, `last_name`, `suffix`). A `user_id`-null intern has no display name — admin should create the `users` row together with the intern (see Decision #3).

### Migration: `relax_documents_department_nullable`

`documents.department_id` is currently `NOT NULL` (`restrictOnDelete`) and 201-Files uploads require it. Intern docs have no department, so relax it to nullable.

```php
// up()
Schema::table('documents', function (Blueprint $table) {
    $table->dropForeign(['department_id']);
});
Schema::table('documents', function (Blueprint $table) {
    $table->unsignedBigInteger('department_id')->nullable()->change();
    $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
});
```

### Migration: `add_intern_fields_to_documents`

```php
Schema::table('documents', function (Blueprint $table) {
    $table->foreignId('intern_id')->nullable()->after('department_id')
          ->constrained('interns')->nullOnDelete();
    $table->string('document_period', 100)->nullable();          // "Week 1", "October 2026"
    $table->string('review_status', 20)->default('pending');     // pending | approved | rejected
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->text('review_remarks')->nullable();
});
```

> Existing NOT NULL columns on `documents` that intern uploads must satisfy:
> `document_type_id` (→ intern document type row), `uploaded_by` (→ intern's user), `file_path`, `original_filename`, `system_filename` (unique), `mime_type`.
> `document_folder_id`, `document_location_id`, `physical_location_id` remain NULL for intern docs.

### Migration: `add_intern_flags_to_document_types`

```php
Schema::table('document_types', function (Blueprint $table) {
    $table->boolean('is_intern_document')->default(false);
    $table->boolean('is_optional')->default(false);
});
```

### Migration: `add_partner_agency_flag_to_companies`

```php
Schema::table('companies', function (Blueprint $table) {
    $table->boolean('is_partner_agency')->default(false);
});
```

> Reusing `companies` as partner agencies is acceptable but semantically muddy; flagged. The `is_partner_agency` flag keeps the partner list filtered.

---

## 🏗️ Implementation Roadmap

---

### Phase 1 — Database, Roles & Access Control *(~2–3 days)*

- [ ] Migration: `create_interns_table`
- [ ] Migration: `relax_documents_department_nullable`
- [ ] Migration: `add_intern_fields_to_documents`
- [ ] Migration: `add_intern_flags_to_document_types`
- [ ] Migration: `add_partner_agency_flag_to_companies`
- [ ] Seeder: intern document types (AR, DTR required; RESIGN, CERT optional; `is_intern_document = true`)
- [ ] `Intern` model: casts, relations (`user`, `partnerAgency`, `hostDepartment`, `documents`), soft deletes, helpers (`hoursRemaining`, `progressPercent`, `isExpiringSoon`)
- [ ] `Document` model: add `intern()` relation + `internDocuments()` scope; add review casts
- [ ] `User` model: add `isPesoManager()` and `isIntern()` helpers
- [ ] `UserRequest`: extend whitelist → `Rule::in(['admin','encoder','viewer','peso_manager','intern'])`
- [ ] `UserFactory`: add `pesoManager()` / `intern()` states
- [ ] Policies: `InternPolicy` (viewAny/view all → admin+peso; view self → intern; create/update/delete → admin), `InternDocumentPolicy` (upload self; review → admin+peso; view self)
- [ ] Register `ForcePasswordChange` middleware in `bootstrap/app.php`
- [ ] Login redirect: send `intern` role to `/my-internship`

---

### Phase 2 — Backend Controllers & Logic *(~3–5 days)*

- [ ] **`InternController`** — CRUD for intern records
  - `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`
  - Authorized via `InternPolicy` (Admin full; PESO read; Intern self via `show`/redirect)
- [ ] **`InternDocumentController`** — upload, review, download
  - `store` (upload) — Intern only (or Admin on behalf)
  - `review` (approve / reject + remarks) — PESO Manager / Admin
  - `download` / `preview` — role-aware (intern limited to own)
- [ ] **`InternDocumentUploadService`** — mirror `DepartmentDocumentUploadService`:
  - Validate, store file on `local` disk, create `documents` row
  - For DTR: increment `interns.rendered_hours` **synchronously inside a DB transaction**
  - Fire audit log + notifications
- [ ] Update **`DashboardController`**: intern stats (total, active/inactive, by agency, expiring) + intern self-service data
- [ ] Update **`ReportController`**: intern exports (list, hours summary, agency summary, expiry) — widen routes to `role:admin,peso_manager`
- [ ] Update **`AuditService`**: add `Intern` to the target-name `match`; log intern/doc mutations
- [ ] Notification classes: `InternDocumentUploaded` (→ PESO + Admin), `InternDocumentReviewed` (→ intern)
- [ ] Command `CheckInternContractExpiry` (daily: flip expired `status` → `inactive` + notify within 7 days)
- [ ] Command `CheckInternCompletion` (notify Admin & PESO at ≥ 90% of `required_hours`)

---

### Phase 3 — Frontend Views *(~4–6 days)*

Mirror the existing Bootstrap modal + Alpine CRUD pattern; reuse `public/css/style.css` classes and `public/js` conventions.

- [ ] **Intern self-service page** (`/my-internship`)
  - Hours progress bar (rendered vs required)
  - Contract start/end + days-remaining countdown
  - Document upload form (drag-drop, period selector, hours input for DTR)
  - Uploaded documents list with `pending` / `approved` / `rejected` badges
- [ ] **Admin / PESO intern list** (`/interns`)
  - Table: name, partner agency, contract dates, hours rendered, status
  - Filters: active / inactive / expiring soon / by agency
- [ ] **Intern profile page** (`/interns/{intern}`)
  - Full details, documents list, review action buttons (approve/reject + remarks)
- [ ] **Intern create / edit** (modal partials like existing forms)
- [ ] **Dashboard updates**
  - Intern count cards; partner agency pie; contracts-expiring table; interns-by-month line
- [ ] **Document upload form** partial (drag-drop, period, hours, validation feedback)
- [ ] **Sidebar** (`resources/views/layouts/sidebar.blade.php`): new role-gated GIMS section (Interns, My Internship, Intern Reports)

---

### Phase 4 — Notifications, Automation & Tests *(~2–3 days)*

- [ ] Notification: **Document Uploaded** → PESO Manager(s) + Admin
- [ ] Notification: **Document Reviewed** → uploading intern
- [ ] Scheduler: `CheckInternContractExpiry` daily
- [ ] Scheduler: `CheckInternCompletion`
- [ ] **Pest tests** (Pest is available): access-control tests for roles/policies, upload validation, hours accumulation, expiry command

---

## 🔒 Access Control Matrix

| Action | Admin | PESO Manager | Intern |
|---|---|---|---|
| View all interns | ✅ | ✅ | ❌ |
| View own intern record | ✅ | ✅ | ✅ (self only) |
| Create / edit intern records | ✅ | ❌ | ❌ |
| Delete intern records | ✅ | ❌ | ❌ |
| Set contract dates | ✅ | ❌ | ❌ |
| Upload own documents | ✅ | ❌ | ✅ |
| View all uploaded documents | ✅ | ✅ | ❌ |
| Approve / reject documents | ✅ | ✅ | ❌ |
| View dashboard stats | ✅ | ✅ | ❌ (own page) |
| Manage users | ✅ | ❌ | ❌ |
| System settings | ✅ | ❌ | ❌ |
| Export reports | ✅ | ✅ | ❌ |
| View audit logs | ✅ | ❌ | ❌ |

---

## 📁 New Files to Create

```
app/
├── Models/
│   └── Intern.php
├── Http/Controllers/
│   ├── InternController.php
│   └── InternDocumentController.php
├── Http/Requests/
│   ├── StoreInternRequest.php
│   └── StoreInternDocumentRequest.php
├── Policies/
│   ├── InternPolicy.php
│   └── InternDocumentPolicy.php
├── Services/
│   └── InternDocumentUploadService.php
├── Notifications/
│   ├── InternDocumentUploaded.php
│   └── InternDocumentReviewed.php
└── Console/Commands/
    ├── CheckInternContractExpiry.php
    └── CheckInternCompletion.php

database/migrations/
├── xxxx_create_interns_table.php
├── xxxx_relax_documents_department_nullable.php
├── xxxx_add_intern_fields_to_documents.php
├── xxxx_add_intern_flags_to_document_types.php
└── xxxx_add_partner_agency_flag_to_companies.php

database/seeders/
└── InternDocumentTypeSeeder.php

resources/views/
├── interns/
│   ├── index.blade.php
│   ├── show.blade.php
│   ├── create.blade.php
│   └── edit.blade.php
└── my-internship/
    └── index.blade.php        ← Intern self-service page

routes/web.php                 ← Add intern & PESO routes (role-gated)
tests/Feature/                 ← Pest access-control + workflow tests
```

---

## ⚙️ Config / Environment Notes

- **Queue**: `QUEUE_CONNECTION=database` with no worker guaranteed → intern processing is **synchronous**.
- **Search**: `SCOUT_DRIVER=database`, MeiliSearch not running → use plain SQL queries; **do not** add a Meilisearch index for interns now.
- **Storage**: `local` disk root = `storage/app/private` (private files served via role-aware routes).
- **Required extensions**: `gd`, `zip` (already enabled in `C:\xampp\php\php.ini`).

---

## ✅ Locked Decisions

| # | Decision | Value |
|---|---|---|
| 1 | Intern document storage | **Reuse `documents` table**; `department_id` made nullable |
| 2 | `interns.employee_id` link | **Removed** — interns are separate |
| 3 | Monitoring unit | **Hours** (`required_hours`, `rendered_hours`) |
| 4 | Intern profile fields | **Include** school, course, contact, host department, supervisor, batch |
| 5 | Frontend pattern | **Bootstrap 5 + Alpine + Chart.js** (mirror existing CRUD/upload UI) |
| 6 | Document review states | **`pending` / `approved` / `rejected`** (+ `review_remarks`) |
| 7 | Intern post-login landing | **Redirect to `/my-internship`** |
| 8 | Contract expiry | **Daily command** flips `status` (stored/queryable) |
| 9 | Required hours | **Per-intern column, default 486**, editable |
| 10 | DTR data entry | **Manual hours input** on the upload form (no OCR) |
| 11 | Intern user accounts | **Admin** creates both the `users` row and the `interns` row |
| 12 | Partner agencies | Reuse `companies` + `is_partner_agency` flag |
| 13 | Search | **Defer** Meilisearch — use SQL |
| 14 | File storage | **Local** disk (consistent with current system) |
