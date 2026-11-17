#!/usr/bin/env bash
# Restore GIMS from a backup produced by scripts/backup.sh.  ***DESTRUCTIVE***
#
# The target database is dropped and recreated from the dump, and (optionally)
# storage/app is replaced by the archive. Uploaded files and database rows
# created after the backup are lost.
#
# Usage: ./scripts/restore.sh --db <path/to/db.dump> [options]
#
#   --db <file>            required: pg_dump custom-format archive
#   --storage <file>       storage.tar.gz from backup.sh (recommended)
#   --yes                  required: acknowledge the overwrite
#   --skip-safety-backup   do NOT take a pre-restore backup first. Use only when
#                          the current database is already unusable.
#   --no-verify            skip the post-restore ./scripts/verify.sh
#   -h, --help             show this help
#
# Safety model
#   1. artifacts are validated (checksums + pg_restore --list + tar -t) BEFORE
#      anything is stopped;
#   2. --yes AND a typed RESTORE confirmation are required;
#   3. a fresh verified backup is taken first unless explicitly skipped;
#   4. the tunnel, nginx, app, queue and scheduler are stopped for the restore,
#      so clients get an error instead of partially restored data.

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

DB_FILE=""
STORAGE_FILE=""
YES=0
SAFETY=1
POST_VERIFY=1

usage() { sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --db) DB_FILE="${2:-}"; shift ;;
        --storage) STORAGE_FILE="${2:-}"; shift ;;
        --yes) YES=1 ;;
        --skip-safety-backup) SAFETY=0 ;;
        --no-verify) POST_VERIFY=0 ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
    shift
done

require_env_file
require_docker
need_cmd tar

mkdir -p "$BACKUP_DIR"

[[ -n "$DB_FILE" ]] || die "--db <path/to/db.dump> is required (try --help)"
[[ -f "$DB_FILE" ]] || die "database dump not found: $DB_FILE"
DB_FILE="$(cd -- "$(dirname -- "$DB_FILE")" && pwd)/$(basename -- "$DB_FILE")"

if [[ -n "$STORAGE_FILE" ]]; then
    [[ -f "$STORAGE_FILE" ]] || die "storage archive not found: $STORAGE_FILE"
    STORAGE_FILE="$(cd -- "$(dirname -- "$STORAGE_FILE")" && pwd)/$(basename -- "$STORAGE_FILE")"
fi

(( YES )) || die "refusing to restore without --yes (this destroys the current database)"

DB_NAME="$(db_name)"
DB_USER="$(db_user)"

# ---------------------------------------------------------------------------
# Validate artifacts before touching anything
# ---------------------------------------------------------------------------
if ! compose ps --status running --services 2>/dev/null | grep -qx postgres; then
    log "Starting postgres for archive validation"
    compose up -d postgres
fi
wait_for "postgres is accepting connections" 60 2 postgres_ready \
    || die "postgres is not reachable"

sums_file="$(dirname -- "$DB_FILE")/SHA256SUMS"
if [[ -f "$sums_file" ]]; then
    step "verifying SHA256 checksums"
    (cd -- "$(dirname -- "$DB_FILE")" && sha256sum --check --status SHA256SUMS) \
        || die "checksum verification failed for the backup in $(dirname -- "$DB_FILE")"
    ok "checksums match"
else
    warn "no SHA256SUMS next to the dump — skipping checksum verification"
fi

step "validating the database archive with pg_restore --list"
compose exec -T postgres pg_restore --list <"$DB_FILE" >/dev/null \
    || die "pg_restore cannot read $DB_FILE"

if [[ -n "$STORAGE_FILE" ]]; then
    step "validating the storage archive"
    tar -tzf "$STORAGE_FILE" >/dev/null || die "storage archive is corrupt: $STORAGE_FILE"
fi
ok "artifacts are readable"

# ---------------------------------------------------------------------------
# Confirmation and safety net
# ---------------------------------------------------------------------------
confirm_typed "RESTORE" "This REPLACES database '$DB_NAME'${STORAGE_FILE:+ and all uploaded files}. There is no undo."

if (( SAFETY )); then
    log "Taking a pre-restore safety backup"
    "$SCRIPT_DIR/backup.sh" \
        || die "the safety backup failed — aborting. Use --skip-safety-backup only if the current database is already unusable."
else
    warn "pre-restore safety backup skipped (--skip-safety-backup)"
fi

# ---------------------------------------------------------------------------
# Quiesce writers, then restore
# ---------------------------------------------------------------------------
log "Stopping tunnel, nginx, app, queue and scheduler"
compose stop cloudflared nginx queue scheduler app >/dev/null 2>&1 || true

escaped_db_name="${DB_NAME//\'/\'\'}"
step "terminating remaining connections to '$DB_NAME'"
compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d postgres -c \
    "select pg_terminate_backend(pid) from pg_stat_activity where datname = '$escaped_db_name' and pid <> pg_backend_pid()" >/dev/null \
    || die "could not terminate connections to '$DB_NAME'"

step "dropping and recreating '$DB_NAME'"
compose exec -T postgres dropdb --if-exists -U "$DB_USER" "$DB_NAME" \
    || die "dropdb failed — nothing was restored"
compose exec -T postgres createdb -U "$DB_USER" -O "$DB_USER" "$DB_NAME" \
    || die "createdb failed — the database does not exist; re-run after fixing permissions"

step "pg_restore (no owners/privileges, exit on first error)"
if ! compose exec -T postgres pg_restore \
    --no-owner \
    --no-privileges \
    --exit-on-error \
    -U "$DB_USER" \
    -d "$DB_NAME" <"$DB_FILE"; then
    die "pg_restore failed. The database is incomplete — restore from the safety backup at $BACKUP_DIR before starting the app."
fi

table_count="$(compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -tAc \
    "select count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'" \
    | tr -d '[:space:]' || true)"
[[ "$table_count" =~ ^[0-9]+$ ]] || die "could not count tables after the restore — check postgres logs"
(( table_count > 0 )) || die "the restore produced an empty schema — wrong or empty dump?"

for required in users interns documents; do
    present="$(compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -tAc \
        "select count(*) from information_schema.tables where table_schema = 'public' and table_name = '$required'" \
        | tr -d '[:space:]' || true)"
    [[ "$present" == "1" ]] || die "restored schema is missing the '$required' table"
done
ok "database restored ($table_count tables; users/interns/documents present)"

if [[ -n "$STORAGE_FILE" ]]; then
    step "restoring storage/app (existing files replaced)"
    compose run --rm --no-deps -T -u www-data app sh -c \
        'rm -rf /var/www/html/storage/app && tar -xzf - -C /var/www/html/storage' \
        <"$STORAGE_FILE" \
        || die "storage restore failed — database is restored but files may be incomplete"
    ok "storage restored"
else
    warn "storage was NOT restored (no --storage given); uploaded files may not match the database"
fi

# ---------------------------------------------------------------------------
# Bring the stack back
# ---------------------------------------------------------------------------
log "Starting services"
compose up -d --remove-orphans
wait_for "postgres is accepting connections" 60 2 postgres_ready \
    || die "postgres did not come back up"

app_artisan config:cache || warn "config:cache failed"
app_artisan cache:clear || warn "cache:clear failed (stale cache rows may remain)"

wait_for "nginx is serving /healthz" 30 2 nginx_ready || die "the stack is not healthy"
compose restart app queue scheduler >/dev/null
wait_for "nginx is serving /healthz" 30 2 nginx_ready || die "the stack is not healthy"

printf '%s restore OK dump=%s storage=%s tables=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$DB_FILE" "${STORAGE_FILE:-none}" "$table_count" \
    >>"$BACKUP_DIR/restore.log"

if [[ "$(env_get SCOUT_DRIVER)" == "meilisearch" ]]; then
    warn "Meilisearch was not part of this restore; its index may not match the database"
fi

log "Restore complete"
cat <<EOF

Next steps
  ./scripts/verify.sh                 # health, auth and exposure checks
  ./scripts/deploy.sh                 # if the dump predates the deployed code
                                      # (deploy --migrate to apply new migrations)

EOF

if (( POST_VERIFY )); then
    "$SCRIPT_DIR/verify.sh" || die "restore finished but verification failed — investigate before declaring success"
fi
