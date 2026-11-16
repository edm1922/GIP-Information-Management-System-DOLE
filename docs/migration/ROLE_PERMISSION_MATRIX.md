# Phase 0 — Role & Permission Matrix

**Basis:** `app/Models/User.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `routes/web.php`, `app/Policies/InternPolicy.php`, `app/Policies/InternDocumentPolicy.php`, and controller/route usage patterns. This reflects **current implemented access control**; API must enforce equivalent server-side authorization.

---

## 1. Roles

| Role (stored value) | Helpers | Intended users (as implemented) |
|---|---|---|
| `admin` | `User::isAdmin()`, `hasRole('admin')` | Full administrative access |
| `peso_manager` | `User::isPesoManager()` | Management/review/report access for internship workflows |
| `intern` | `User::isIntern()` | Self-service intern access (own record/documents) |

Role checks are performed via custom `role:*` middleware and `hasRole(...)`; no Spatie/permission package detected in inspected dependencies.

---

## 2. Access control mechanisms

| Mechanism | Evidence | Notes |
|---|---|---|
| Route middleware `role:...` | `EnsureUserHasRole` + route groups/routes | Aborts 403 if unauthenticated or role not allowed |
| Policy abilities via `can:` | `interns.index` (`viewAny`), `interns.show` (`view,intern`), `intern-documents.index` (`viewAny`), preview/download (`download,document`) | Model/policy-driven checks |
| Controller/request/service checks | e.g. document store/review flows | Must be audited per action during API conversion (some routes lack explicit role middleware but still must authorize) |
| Mandatory password change | `ForcePasswordChange` middleware on `web` | Restricts navigation until password changed (except allowed routes) |

---

## 3. Feature/action matrix

| Module / action | `admin` | `peso_manager` | `intern` | Enforcement evidence |
|---|---|---:|---:|---|
| **Auth**: login/logout, confirm/update password | Yes (as authenticated user) | Yes | Yes | `routes/auth.php`, auth controllers |
| **Mandatory password change** | Required when flagged | Required when flagged | Required when flagged | `ForcePasswordChange` middleware |
| **Dashboard** `/dashboard` | Yes | Yes | Yes (if verified/authenticated) | `auth`,`verified` route middleware |
| **Profile** view/update/delete | Yes (own) | Yes (own) | Yes (own) | `ProfileController` under auth |
| **Notifications** list/mark read/all | Yes | Yes | Yes | `NotificationController` under auth |
| **Settings: Companies** (CRUD, toggle active) | Yes | No | No | `role:admin` group |
| **Settings: Document Types** (list/create/update/delete) | Yes | No | No | `role:admin` group |
| **Settings: Users** (CRUD, reset password, toggle status) | Yes | No | No | `role:admin` group |
| **Audit log report** `/reports/audit-log` | Yes | No | No | `role:admin` |
| **Internship reports** view/export (`/reports/intern`, exports) | Yes | Yes | No | `role:admin,peso_manager` |
| **Interns: list** | Yes | Yes | No | `InternPolicy@viewAny` |
| **Interns: view detail** | Yes (any) | Yes (any) | Yes (own only) | `InternPolicy@view` |
| **Interns: create/edit/update** | Yes | No | No | `role:admin` |
| **Interns: soft delete/restore/force delete** | Yes | No | No | `role:admin` |
| **Intern documents: list/index** | Yes | Yes | No (policy) | `InternDocumentPolicy@viewAny` |
| **Intern documents: folder browser** | Yes | Yes | No | `role:admin,peso_manager` on folder routes |
| **Intern documents: upload/store** | Yes (as implemented) | Context-dependent (policy allows admin; intern self for own) | Yes (own record only) | `InternDocumentPolicy@create($user,$intern)`; route is auth-only so controller/request must enforce |
| **Intern documents: preview/download** | Yes (any) | Yes (any) | Yes (own only) | `can:download,document` + `InternDocumentPolicy@download/view` |
| **Intern documents: review/approve/reject** | Yes | Yes | No | `role:admin,peso_manager` + `InternDocumentPolicy@review` |
| **Intern documents: delete** | Yes (policy ability) | No | No | `InternDocumentPolicy@delete` (route exposure to verify) |
| **My Internship: DTR tab** `/my-internship` | No (route role-restricted) | No | Yes | `role:intern` |
| **My Internship: 201 Files** `/my-internship/201-files` | No | No | Yes | `role:intern` |

---

## 4. Policy details (current)

### `InternPolicy`
- `viewAny`: `admin|peso_manager`
- `view`: `admin|peso_manager` OR (`intern` AND `intern.user_id == user.id`)
- `create/update/delete/restore/forceDelete`: `admin` only

### `InternDocumentPolicy`
- `viewAny`: `admin|peso_manager`
- `view`: `admin|peso_manager` OR (`intern` AND `document.intern.user_id == user.id`)
- `create(user, intern?)`: `admin` OR (`intern` AND intern belongs to user)
- `review`: `admin|peso_manager`
- `download`: same as `view`
- `delete`: `admin`

---

## 5. Migration implications

- Preserve exact role identifiers and all allow/deny outcomes per action and resource ownership.
- API endpoints must return `401/403` consistently and never rely on frontend-only gating.
- Intern ownership checks must use authoritative server-side relationships (`intern.user_id`, document→intern ownership).
- Review, export, and document access are sensitive: enforce policies on every read/write/download/preview endpoint.
- Navigation/UI visibility in Next.js must mirror these permissions but must not be the source of truth.

## 6. Verification items
- Confirm any additional controller-level checks not expressed in routes/policies (especially uploads, reports filters, notifications access by ID).
- Confirm behavior for inactive users (`is_active`) and soft-deleted users/interns/documents across all roles.
- Confirm whether `peso_manager` has any implicit capabilities beyond those listed (source did not show others, but verify in code paths).
