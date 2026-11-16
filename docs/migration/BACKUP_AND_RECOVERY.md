# Phase 0 — Backup & Recovery Plan (Design)

**Goal:** Protect the existing GIMS PostgreSQL data, uploaded/private documents, and critical configuration so the self-hosted deployment can be restored after failure, corruption, or cutover issues. **No backup/restore tooling was executed as part of this audit.**

---

## 1. Scope and principles

- **In scope:** PostgreSQL database, Laravel private/public storage assets required by the app (especially uploaded intern documents and any generated files), application configuration/secrets management records (not committed), deployment manifests (Compose/tunnel/Nginx configs) once created.
- **Preservation-first:** never treat frontend/API migration as a reason to reset or replace production data.
- **Verification over assumption:** a backup is successful only when the artifact exists, is readable, and can be restored in a test.
- **Defense in depth:** keep at least one independent copy outside the Windows 11 host computer.

---

## 2. Backup targets

| Target | Contents | Priority | Notes |
|---|---|---|---|
| PostgreSQL database | All schemas/tables/data, including users, interns, documents metadata, DTR days, audit logs, notifications, jobs/sessions/cache as applicable | Critical | Use logical dumps (`pg_dump` custom/plain as chosen) and/or physical strategy as appropriate; include roles/extensions if needed for restore fidelity |
| Uploaded/private documents | Files referenced by DB (e.g. intern document scans/uploads under private storage) | Critical | Must preserve directory structure/paths or mapping required by DB references |
| Application storage state | Other persistent `storage/` data required by runtime (e.g. generated exports if persisted) | High | Define inclusions/exclusions explicitly |
| Search index data | Meilisearch data volume/index snapshots (optional if rebuildable) | Medium | Reindex-from-DB may be acceptable if documented and tested; prefer backup if rebuild is slow/risky |
| Configuration & infra | `.env` (secured copy), Docker Compose, Nginx/PHP config, `cloudflared` tunnel config/credentials, scheduler/worker definitions | High | Store secrets in secure vault/location; do not commit to Git |
| Code release state | Git commit/tag of deployed backend/frontend | Medium | Enables rollback to known versions |

---

## 3. Backup policy (minimum per spec)

| Requirement | Plan |
|---|---|
| Daily PostgreSQL backups | Automated daily logical backup of production DB with timestamped artifacts |
| Daily or incremental uploaded-document backups | Automated daily sync/snapshot at minimum; incremental allowed if recovery point/objectives met |
| Application configuration backups | Versioned, access-controlled copies of deployment/config artifacts on change and in regular backup set |
| Weekly recovery copies outside hosting computer | Replicate selected backups weekly to independent location (separate machine/cloud/object storage with restricted access) |
| Retention policy | Define and implement (example baseline to finalize): daily 14–30 days, weekly 8–12 weeks, monthly as needed; prune only after verification |
| Backup failure logging | All jobs must emit success/failure logs and alerts/notifications for operator review |
| Documented restore procedures | Maintained runbooks for DB-only, files-only, and full-system restore scenarios |

---

## 4. Automation & storage design (to implement in Phase 2)

- Run backup jobs on Ubuntu VM (cron/systemd timer or scheduled container) with least-privilege credentials.
- Write backups to a dedicated persistent volume/path, then copy/replicate to off-host destination.
- Encrypt backups at rest/in transit where supported (especially off-host copies).
- Protect backup credentials/keys separately from runtime app secrets.
- Avoid backing up transient caches/temp files; include everything required for consistent restore.

**Consistency considerations:**
- Prefer DB backups during low-usage windows; document whether dumps are taken online and expected consistency model.
- For file+DB coherence, capture DB backup and file snapshot close together and document restore ordering/point-in-time expectations.
- If exports or other generated artifacts must be preserved, include their storage paths; otherwise document them as reproducible.

---

## 5. Verification requirements

| Check | Frequency | Acceptance |
|---|---|---|
| Backup job completion status | Every run | Job exits successfully and logs recorded |
| Artifact presence & readability | Every run | File/object exists, non-zero/expected size, checksum/manifests where used |
| Sample integrity checks | Regular (e.g. weekly) | `pg_restore --list` / test parse or equivalent; archive integrity check for files |
| **Test restoration to separate database** | **Before production cutover (mandatory)** and periodically thereafter | Restore completes without errors, key tables/relationships/data counts validated, app can read restored dataset in isolated environment |
| Off-host copy verification | Weekly | Remote copy exists and is retrievable |

> **Never** validate backups only by command exit code.

---

## 6. Restore procedures (runbook outline)

### 6.1 Database restore (isolated test / recovery)
1. Provision clean PostgreSQL instance/database (separate from production).
2. Retrieve selected verified backup artifact.
3. Restore using appropriate tool/options; apply required roles/extensions/config if applicable.
4. Run integrity checks: table counts, critical FK relationships, spot checks (users/interns/documents/dtr_days/audit_logs), migration status compatibility.
5. Point a non-production Laravel instance to restored DB and smoke-test critical flows.

### 6.2 File storage restore
1. Stop or quiesce writers where possible.
2. Restore files to correct persistent storage path/volume with preserved permissions/ownership.
3. Validate DB `file_path`/metadata references resolve to restored files.
4. Test preview/download for representative documents with authorized accounts.

### 6.3 Full service recovery (disaster/cutover rollback support)
1. Restore VM/services or provision replacement runtime (Docker/Compose/Nginx/PHP/tunnel).
2. Restore configuration/secrets securely.
3. Restore PostgreSQL and file storage.
4. Start dependencies (DB, Meilisearch) then app, workers, scheduler, tunnel.
5. Verify health checks, login/auth, document access, jobs/scheduler, and external reachability.

### 6.4 Point-of-failure scenarios to cover in detailed runbooks
- Host reboot/power loss (service auto-recovery)
- DB corruption or accidental data issue (restore to isolated DB, controlled cutover)
- Storage volume loss
- Tunnel/service misconfiguration
- Failed deployment/update rollback

---

## 7. Security & access control for backups

- Restrict backup storage access to authorized operators/service accounts only.
- Encrypt sensitive archives and protect decryption keys.
- Ensure backups do not expose secrets in plaintext logs.
- Apply retention/pruning with auditability.

---

## 8. Implementation checklist (Phase 2 onward)

- [ ] Choose backup tooling/format and destination(s), including off-host target
- [ ] Script automated DB and file backups with logging/alerting
- [ ] Define retention/pruning automation
- [ ] Implement secure config/secret backup process
- [ ] Perform and document **pre-cutover test restore** to separate DB
- [ ] Validate document file restoration and app-level access
- [ ] Add backup/restore steps to operations runbook and cutover plan

## 9. Current audit note
No evidence of automated backup/restore implementation was validated in-repo during Phase 0. Existing `database/database_dump.sql` may be useful for context but must **not** be treated as a verified, current, or sufficient production backup without validation.
