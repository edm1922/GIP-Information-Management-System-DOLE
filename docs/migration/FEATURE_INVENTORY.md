# Phase 0 — Feature Inventory

**Basis:** Inspected routes, controllers, models, policies, services, notifications, views, middleware, and console schedule (2026-11-16). This documents **existing implemented functionality** to preserve; it does not propose new behavior.

---

## 1. Feature map by module

### 1.1 Authentication & account access
| Feature | Roles | Primary routes / entry points | Key implementation evidence | Notes |
|---|---|---|---|---|
| Login | Guest | `GET/POST /login` | `Auth\AuthenticatedSessionController`, `resources/views/auth/login.blade.php` | Username/credential details to confirm in controller/request; register/reset/verify routes currently commented out |
| Logout | Authenticated | `POST /logout` | `Auth\AuthenticatedSessionController@destroy` | Must invalidate session appropriately |
| Password confirmation | Authenticated | `GET/POST /confirm-password` | `Auth\ConfirmablePasswordController` | Sensitive-action reauth flow (usage to confirm in code paths) |
| Password update | Authenticated | `PUT /password` | `Auth\PasswordController` | Profile/account security flow |
| Mandatory password change | Authenticated (flagged users) | `GET/POST /force-password-change` | `ForcePasswordChange` middleware (web), `Auth\ForcePasswordChangeController`, `auth/force-password-change.blade.php` | Blocks access to other routes except allowed set until completed; must preserve |

### 1.2 Dashboard & overview
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| Main dashboard | Authenticated (verified) | `GET /dashboard` | `DashboardController@index`, `resources/views/dashboard.blade.php` | Stats/widgets/cards and role-specific content to be verified in controller/view |

### 1.3 User profile & session-facing account
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| View/edit profile | Authenticated | `GET/PATCH /profile` | `ProfileController`, `profile/edit.blade.php`, partials | Includes profile info/password/update forms per views |
| Delete account/profile action | Authenticated | `DELETE /profile` | `ProfileController@destroy` | Preserve existing constraints/behavior |

### 1.4 Notifications
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| List notifications | Authenticated | `GET /notifications` | `NotificationController@index`, `notifications/index.blade.php` | Database notifications table exists via migration |
| Mark one read | Authenticated | `POST /notifications/{id}/read` | `NotificationController@markAsRead` | — |
| Mark all read | Authenticated | `POST /notifications/mark-all-read` | `NotificationController@markAllAsRead` | — |
| Domain notifications | System-driven | n/a (generated) | `InternDocumentUploaded`, `InternDocumentReviewed`, `InternStatusAlert`, `InternNotificationService` | Trigger points/recipients must be preserved |

### 1.5 Intern management
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| List interns | `admin`, `peso_manager` (policy) | `GET /interns` | `InternController@index`, `InternPolicy@viewAny`, `interns/index.blade.php` | Filters/pagination/search UI to verify |
| View intern details | `admin`, `peso_manager`, or owning intern (policy) | `GET /interns/{intern}` | `InternController@show`, `InternPolicy@view`, `interns/show.blade.php` | Includes related documents/hours/progress context per implementation |
| Create intern | `admin` | `GET/POST /interns/create`, `/interns` | `InternController@create/store`, `interns/create.blade.php`, `_form` | Creates/links user+intern data as implemented; preserve validation/business rules |
| Edit/update intern | `admin` | `GET/PUT /interns/{intern}/edit` | `InternController@edit/update`, `interns/edit.blade.php` | — |
| Soft delete intern | `admin` | `DELETE /interns/{intern}` | `InternController@destroy` | SoftDeletes on model |
| Restore intern | `admin` | `PATCH /interns/{id}/restore` | `InternController@restore` | — |
| Force delete intern | `admin` | `DELETE /interns/{id}/force-delete` | `InternController@forceDelete` | High-impact action; preserve safeguards/behavior |

### 1.6 Intern documents & 201-files workflows (shared `documents` table)
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| Browse/review queue or index | `admin`, `peso_manager` (policy) | `GET /intern-documents` | `InternDocumentController@index`, `InternDocumentPolicy@viewAny`, `intern-documents/index.blade.php` | Exact listing/filter semantics to verify |
| Folder browser (per intern) | `admin`, `peso_manager` | `GET /intern-documents/folders`, `/folders/{intern}` | `InternDocumentController@folders/internFolder`, views `folders.blade.php`, `folder.blade.php` | Organizes documents by intern; preserves 201-files navigation model |
| Upload/store document | Authenticated (policy/request-controlled; interns for own record, admin as applicable) | `POST /intern-documents` | `InternDocumentController@store`, `InternDocumentPolicy@create`, `StoreInternDocumentRequest`, `InternDocumentUploadService`, `_upload-form.blade.php` | Handles metadata, file storage, document type/period/DTR-day payloads as implemented; **critical to preserve** |
| Preview document | Authorized viewers (policy `download`), incl. trashed route binding | `GET /intern-documents/{document}/preview` | `InternDocumentController@preview`, route `withTrashed()` | Inline/preview behavior per controller/viewer components |
| Download document | Authorized viewers (policy `download`), incl. trashed | `GET /intern-documents/{document}/download` | `InternDocumentController@download` | Must remain auth-protected, no public file exposure |
| Review/approve/reject document | `admin`, `peso_manager` | `PATCH /intern-documents/{document}/review` | `InternDocumentController@review`, `ReviewInternDocumentRequest`, `_review-table.blade.php`, `_reject-modal.blade.php` | Updates review status/remarks/reviewer/timestamps; may trigger notifications/hours recalculation side effects (verify) |
| DTR day capture & display | Intern uploaders / reviewers / intern self-service views | Embedded in upload/review/self-service UIs | `DtrDay` model, `InternDocument` DTR helpers/payload, `_dtr-days-picker/_viewer/_trigger` partials, `InternHoursService` | DTR scan is reference; selected days/hours are authoritative per model comments |

### 1.7 Intern self-service
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| My internship (DTR tab) | `intern` | `GET /my-internship` (`my-internship.index`) | `MyInternshipController@index`, `my-internship/index.blade.php`, `_hours-card`, `_documents-table`, calendar partials | Route name preserved for existing notifications/links |
| 201 Files tab | `intern` | `GET /my-internship/201-files` | `MyInternshipController@files`, `my-internship/files.blade.php` | Shows non-DTR intern documents as implemented |
| Unassigned/inactive states | `intern` (contextual) | same area | `my-internship/unassigned.blade.php` | Must preserve access/UX behavior |

### 1.8 Settings — companies (partner agencies)
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| CRUD companies | `admin` | `/settings/companies*` | `CompanyController`, `CompanyRequest`, views under `companies/` | Includes create/edit forms and confirm modal |
| Toggle active status | `admin` | `PATCH /settings/companies/{company}/toggle-active` | `CompanyController@toggleActive` | — |

### 1.9 Settings — document types
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| List/create/update/delete document types | `admin` | `/settings/document-types*` (no create/edit pages) | `DocumentTypeController`, `DocumentTypeRequest`, views under `document-types/` | UI may use inline/modal flows; flags include intern/optional/expiry/max pages |

### 1.10 Settings — user management
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| CRUD users | `admin` | `/settings/users*` | `UserController`, `UserRequest`, views under `users/` | Username/role/status/password-reset related fields/flows to verify |
| Reset user password | `admin` | `POST /settings/users/{user}/reset-password` | `UserController@resetPassword` | Must preserve security/notification behavior as implemented |
| Toggle user status | `admin` | `PATCH /settings/users/{user}/toggle-status` | `UserController@toggleStatus` | Affects login/access (`is_active`) |

### 1.11 Reports & exports
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| Internship reports dashboard/view | `admin`, `peso_manager` | `GET /reports/intern` | `ReportController@internIndex`, `reports/intern.blade.php` | Filters/aggregations to verify |
| Export interns | `admin`, `peso_manager` | `GET /reports/export/interns` | `ReportController@exportInterns` | Spreadsheet/PDF tooling available in deps |
| Export intern hours | `admin`, `peso_manager` | `GET /reports/export/intern-hours` | `ReportController@exportInternHours` | — |
| Export intern agency summary | `admin`, `peso_manager` | `GET /reports/export/intern-agencies` | `ReportController@exportInternAgencySummary` | — |
| Export intern expiry | `admin`, `peso_manager` | `GET /reports/export/intern-expiry` | `ReportController@exportInternExpiry` | — |

### 1.12 Audit & activity logging
| Feature | Roles | Routes | Evidence | Notes |
|---|---|---|---|---|
| View audit/activity log | `admin` | `GET /reports/audit-log` | `ActivityLogController@index`, `AuditLog` model, `AuditService`, `reports/audit-log.blade.php` | Tracks actions/targets/changes/IP/UA; retention/query behavior to verify |

---

## 2. Cross-cutting workflows & system behaviors

| Behavior | Evidence | Preserve/migration note |
|---|---|---|
| Role-based access control | `role` middleware, route `can:` abilities, policies, controller checks | API must enforce identically server-side |
| Mandatory password change gate | `ForcePasswordChange` on web group | Include in API/session UX flow design |
| Soft deletes & restore/force-delete | `Intern` model; routes for restore/force-delete; trashed document preview/download | Data lifecycle and permissions must remain intact |
| DTR-driven hours/progress | `DtrDay`, `InternHoursService`, computed intern attributes, recalc command | Approval/rejection and recalculation side effects must be mapped precisely |
| Scheduled intern status checks | `interns:check-contract-expiry`, `interns:check-completion` daily | Must run reliably on self-hosted scheduler/worker environment |
| Notifications on document/status events | Notification classes/services | Delivery channels, recipients, and read-state behavior to verify |
| Search integration (Scout/Meilisearch) | config + deps; model/index alignment to verify | Preserve existing searchable behavior and authorization boundaries |
| File upload/preview/download security | upload service, controllers, policies, filesystem config | Private storage, ownership checks, no direct public access |

---

## 3. UI/page inventory (parity baseline)

Primary Blade surfaces to rebuild 1:1 in behavior and design: `dashboard`, `layouts/*`, `components/*`, `interns/*` (+ form/confirm), `intern-documents/*` (+ upload/review/reject/DTR partials), `my-internship/*` (+ documents table/hours card/calendar/unassigned), `reports/*`, `companies/*`, `document-types/*`, `users/*`, `notifications/*`, `profile/*`, `auth/*` (active), `errors/*`, `partials/document-preview-overlay`, `partials/intern-work-calendar`.

---

## 4. Open verification items (to close during detailed audit/implementation)

- Exact dashboard metrics/widgets per role.
- Full validation rules and side effects in all FormRequests/controllers (especially uploads, reviews, user/company/document-type mutations, exports).
- Whether any report/export runs synchronously vs queued today and expected runtime size.
- Actual notification channels/storage/real-time behavior.
- Actual Scout-indexed models and index contents vs `config/scout.php` references.
- Any hidden/admin/operator flows outside listed routes (console-only or direct links) that users rely on.
- Precise authorization checks inside controllers/services beyond route middleware declarations.
