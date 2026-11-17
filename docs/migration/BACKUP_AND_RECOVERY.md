# Phase 0 — Backup & Recovery Plan (Design)

**Goal:** Protect the existing GIMS data, uploaded/private documents, and critical configuration so the self-hosted deployment can be restored after failure, corruption, or cutover issues.

> **Implementation status (Phase 2):** the design below is implemented by `deploy/selfhosted/scripts/backup.sh` and `deploy/selfhosted/scripts/restore.sh` (runbook: `deploy/selfhosted/README.md` §10). **No backup or restore has actually been executed yet** — there is no Docker runtime, database client, or approved copy of the production dataset on the development host. A real restore drill on the Ubuntu VM is still a hard cutover gate.

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

## 4. Automation & storage design (implemented in Phase 2)

- Run backup jobs on Ubuntu VM (cron/systemd timer or scheduled container) with least-privilege credentials. → `deploy/selfhosted/README.md` §10 includes the `/etc/cron.d/gims-backup` entry.
- Write backups to a dedicated persistent path (`BACKUP_DIR`, default `deploy/selfhosted/backups/<UTC timestamp>/`), then copy/replicate off-host via `BACKUP_HOOK` (e.g. `rclone sync`).
- **Verification is built into the producer:** `backup.sh` runs `pg_restore --list` against the dump, requires at least one `TABLE` section, runs `tar -tzf` on the storage archive, then writes `SHA256SUMS` + `MANIFEST.txt`. Only after all of that does it write `.last-verified-backup`.
- **Migration gate:** `deploy.sh --migrate` refuses to run unless that marker is younger than 24 hours, so "no verified backup" cannot silently become "migration ran anyway".
- Retention: `BACKUP_RETENTION_DAYS` (default 14) prunes old sets but always keeps the newest `BACKUP_KEEP_MIN` (default 7); pruning only touches directories named `20*`.
- **Restore is separate and destructive by design:** `restore.sh` validates checksums/dump/archive *before* stopping anything, requires `--yes` and a typed `RESTORE`, takes a fresh safety backup, then stops `cloudflared`/`nginx`/`app`/`queue`/`scheduler`, recreates the database from the dump, replaces `storage/app`, restarts, and runs `verify.sh`.
- Encrypt backups at rest/in transit where supported (especially off-host copies).
- Protect backup credentials/keys separately from runtime app secrets. Note `.env` (with `APP_KEY`) is **not** in the backup set — keep an encrypted copy in a password manager.

**Consistency considerations:**
- `pg_dump --format=custom` is a consistent online snapshot of the database; the storage archive is taken immediately after it in the same run, so DB/file skew is bounded by the archive duration. The runbook documents that a restore of `db.dump` without `storage.tar.gz` can leave `documents.file_path` rows pointing at missing files.
- Prefer running backups in a low-usage window; `BACKUP_HOOK` failures are reported as warnings because the local artifact is still valid — but an un-replicated backup is a single point of failure.
- Meilisearch data is intentionally **not** backed up: no model in this codebase is Scout-searchable, so it is rebuildable/expendable.

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

- [x] Choose backup tooling/format and destination(s) — `pg_dump -Fc` + `tar.gz`, local `backups/`, off-host via `BACKUP_HOOK`
- [x] Script automated DB and file backups with logging — `scripts/backup.sh` (`backup.log`, non-zero exit on failure, partial set removed)
- [x] Define retention/pruning automation — `BACKUP_RETENTION_DAYS` / `BACKUP_KEEP_MIN`
- [x] Implement secure config/secret backup process — gitignored `.env`/credentials; documented password-manager copy of `APP_KEY`
- [x] Provide the restore runbook and tooling — `scripts/restore.sh` + README §10
- [ ] Perform and document **pre-cutover test restore** to a separate DB — blocked until the Ubuntu VM and the authoritative dataset exist
- [ ] Validate document file restoration and app-level access — same blocker
- [ ] Wire backup/restore steps into the cutover checklist — partially done (README §11); final sign-off after a real drill

## 9. Current audit note

Phase 0 found no automated backup/restore implementation in-repo. Phase 2 added the scripts described above, but they have not been executed: this workstation has no Docker daemon, no `psql`/MySQL client, and CLI PHP only has `pdo_sqlite`, so the database engine of the authoritative dataset is still unconfirmed. `database/database_dump.sql` must **not** be treated as a verified, current, or sufficient production backup without validation.
