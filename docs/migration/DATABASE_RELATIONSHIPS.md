# Phase 0 — Database & Relationship Inventory

**Basis:** `database/migrations/*` (74 files), model classes/relations/casts, and config defaults. **No live database was queried** in this audit; runtime schema, indexes, FKs, triggers, row data, and `migrate:status` must be verified against the actual PostgreSQL instance before any migration-phase changes.

---

## 1. Database configuration (current)

- Default/example connection: PostgreSQL (`.env.example` `DB_CONNECTION=pgsql`, `DB_SSLMODE=require`; `config/database.php` supports pgsql with env-driven host/port/db/user/password/sslmode/search_path).
- Sessions/cache/queue commonly DB-backed in example (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
- Queue tables configured: `jobs`, `job_batches`, `failed_jobs` (UUID driver default in config).

**Preservation rule:** retain existing PostgreSQL database, tables, relationships, PKs/FKs, and data. Laravel remains the only DB client in target architecture.

---

## 2. Migration timeline (high-level)

| Era (by filenames) | What migrations indicate | Relevance |
|---|---|---|
| 0001_01_01_* | Core Laravel tables: users, cache, jobs | Base framework/storage tables |
| 2026_03_* | Companies, departments, employees, document types, physical/storage structures, documents, audit logs, folder/location refactors, user/dept access, document folders/locations/expiry notifications/notifications, hiring events, etc. | Significant 201-files/employee/department domain evolution and iterative refactoring |
| 2026_04_* | User active flag, company scoping/storage, interns table, partner agency flag, intern flags on document types, documents adjusted for intern use, max pages, etc. | Transition toward intern-centric workflows |
| 2026_10_16_* | `remove_legacy_201files_and_departments` | Legacy structures removed/changed; **must not be reintroduced or assumed present** |
| 2026_11_01_* | DTR days table, rendered hours decimal change, beneficiary on interns | Current DTR/hours model foundations |

> Because of heavy refactoring, **current effective schema must be derived from applying/inspecting all migrations in order on the real DB**, not inferred from early migration names alone.

---

## 3. Core domain entities (model-level)

### 3.1 `users` (`App\Models\User`)
- **Evidence:** `0001_01_01_000000_create_users_table`, multiple later user-modifying migrations (username/names/password reset flags/active state etc. inferred from names and model fillable/casts), model `SoftDeletes`.
- **Key fields (model):** `first_name`, `middle_name`, `last_name`, `suffix`, `username`, `password`, `must_change_password`, `role`, `is_active`, `last_active_at` (+ `email_verified_at` cast present).
- **Relationships:** `hasMany(AuditLog::class)`, `hasOne(Intern::class)`.
- **Authorization roles:** string values used in code: `admin`, `peso_manager`, `intern`.

### 3.2 `interns` (`App\Models\Intern`)
- **Evidence:** `2026_04_15_000001_create_interns_table`, `2026_11_01_000003_add_beneficiary_to_interns_table`, model `SoftDeletes`.
- **Key fields (model):** `user_id`, `partner_agency_id`, contract dates, `required_hours` (int), `rendered_hours` (float), `status`, `intern_type`, `batch`, `beneficiary`, `school`, `course`, `contact_number`, `supervisor`.
- **Constants:** statuses `active|inactive|completed`; types `GIP|OJT|SPES|Work Immersion`.
- **Relationships:** `belongsTo(User)`, `belongsTo(Company, 'partner_agency_id')`, `hasMany(InternDocument)`.
- **Derived behavior:** hours/progress/expiry helpers via `InternHoursService` and date math; scopes `active`, `expiringSoon`.

### 3.3 `companies` (`App\Models\Company`)
- **Evidence:** `2026_03_09_000001_create_companies_table`, `2026_04_15_000002_add_partner_agency_flag_to_companies`.
- **Key fields (model):** `name`, `code`, `is_active`, `is_partner_agency` (default true).
- **Relationships:** `hasMany(Intern, 'partner_agency_id')`.

### 3.4 `document_types` (`App\Models\DocumentType`)
- **Evidence:** created 2026-03-09 with later alterations (target/required/max pages/intern flags added/removed/changed across migrations).
- **Key fields (model):** `name`, `code`, `has_expiry`, `max_pages`, `is_intern_document`, `is_optional`.
- **Relationships:** `hasMany(InternDocument)`.

### 3.5 `documents` table / intern document model (`App\Models\InternDocument`)
- **Evidence:** `2026_03_09_000007_create_documents_table` plus many refactors (owner/department constraints, folder/location relations, intern fields, nullable department relaxation, etc.); `2026_10_16_000001_remove_legacy_201files_and_departments.php` affects legacy context.
- **Model mapping:** `InternDocument` uses table `documents`, `SoftDeletes`, and a **global scope** restricting to `intern_id IS NOT NULL`.
- **Key fields (model fillable):** `intern_id`, `document_type_id`, `uploaded_by`, file/path/name/size/mime/page fields, `status`, `document_period`, review fields (`review_status`, `reviewed_by`, `reviewed_at`, `review_remarks`), `metadata` (array).
- **Relationships:** `belongsTo(Intern)`, `belongsTo(DocumentType)`, `hasMany(DtrDay,'document_id')`, `belongsTo(User,'uploaded_by')`, `belongsTo(User,'reviewed_by')`.
- **Critical note:** Documents table has historically served multiple categories; intern isolation is implemented in model scope and business logic. **Do not split/merge document categories without explicit approved migration plan.**

### 3.6 `dtr_days` (`App\Models\DtrDay`)
- **Evidence:** `2026_11_01_000001_create_dtr_days_table` (+ rendered hours precision changes elsewhere).
- **Key fields (model):** `document_id`, `intern_id`, `work_date` (date), `hours` (float).
- **Relationships:** `belongsTo(InternDocument,'document_id')`, `belongsTo(Intern)`.
- **Behavior:** `counted` scope includes only days whose document is not rejected (and not soft-deleted via relation).

### 3.7 `audit_logs` (`App\Models\AuditLog`)
- **Evidence:** `2026_03_09_000008_create_audit_logs_table`, `2026_03_23_143000_upgrade_audit_logs_table`, target name additions.
- **Key fields (model):** `user_id`, `action`, `model_type`, `model_id`, `target_name`, `description`, `changes` (array), `ip_address`, `user_agent`; timestamps disabled in model (`created_at` cast present).
- **Relationships:** `belongsTo(User)`, `morphTo('model')->withTrashed()`.

---

## 4. Other tables/structures indicated by migrations (verify in live DB)

The migration set also references/entities such as (non-exhaustive): cache/jobs/framework tables, departments/department-user access (later legacy removal), employees and related storage/folder/location hierarchies, physical/cabinet/rack/slot and flattened folder/document location structures, document folders, document expiry notifications, notifications table, hiring events, bank types/ATM status (employee-era), company scoping for 201 storage, and various columns/index/constraint adjustments.  
**Action:** produce a definitive table/column/FK/index inventory from the production PostgreSQL schema (`information_schema`, `pg_indexes`, FK catalogs) and reconcile with models/controllers before API and deployment cutover.

---

## 5. Relationships summary (confirmed in models)

```text
User 1---0..1 Intern
Company (partner_agency) 1---0..* Intern (partner_agency_id)
Intern 1---0..* InternDocument (documents.intern_id, scoped)
DocumentType 1---0..* InternDocument
User 1---0..* InternDocument (uploaded_by)
User 1---0..* InternDocument (reviewed_by)
InternDocument 1---0..* DtrDay (document_id)
Intern 1---0..* DtrDay
User 1---0..* AuditLog
AuditLog morphTo model (polymorphic target, incl. soft-deleted)
```

> Additional relationships may exist in DB/migrations not represented by current `app/Models` classes; confirm against live schema.

---

## 6. Data integrity & preservation constraints

- Preserve all PKs/FKs/unique constraints/indexes unless an explicitly approved migration is required.
- Preserve soft-delete semantics and any historical records.
- Preserve `documents` shared-table semantics (intern vs other categories/scopes), file metadata, and DB-to-storage path references.
- Preserve DTR day records and their linkage to review status/hours calculations.
- Do not reseed or reset production data; do not run destructive migrations without verified backup and explicit approval.
- Before Phase 1+: capture live `migrate:status`, full schema export, and FK graph.

---

## 7. Open DB verification checklist

- [ ] Full table list, columns, types, nullability, defaults.
- [ ] Primary keys, foreign keys, unique/check constraints, indexes.
- [ ] Triggers/functions/views (if any).
- [ ] Current migration history in production (`migrations` table).
- [ ] Orphaned records / integrity issues relevant to migration.
- [ ] Actual storage of document file paths vs filesystem layout.
- [ ] Scout-indexed tables/models alignment with current schema.
