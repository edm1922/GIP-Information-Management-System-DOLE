#!/usr/bin/env bash
# Take a verified GIMS backup: PostgreSQL custom-format dump + private storage.
#
# A backup is only "verified" (and only writes .last-verified-backup, which
# deploy.sh --migrate requires) after BOTH artifacts pass an integrity check.
#
# Usage: ./scripts/backup.sh [--db-only] [--no-prune] [--help]
#
# Environment:
#   BACKUP_DIR             destination root (default: ./backups)
#   BACKUP_RETENTION_DAYS  delete backups older than N days (default: 14)
#   BACKUP_KEEP_MIN        always keep the N newest backups (default: 7)
#   BACKUP_HOOK            command run after a successful backup, e.g.
#                          'rclone sync /srv/gims/backups remote:gims-backups'
#
# Exit codes: 0 success (artifacts verified), 1 failure.

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

DB_ONLY=0
PRUNE=1

usage() { sed -n '2,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-only) DB_ONLY=1 ;;
        --no-prune) PRUNE=0 ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
    shift
done

umask 077
require_env_file
require_docker
need_cmd tar

mkdir -p "$BACKUP_DIR"
LOG_FILE="$BACKUP_DIR/backup.log"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$BACKUP_DIR/$TS"

cleanup_partial() {
    if [[ -d "$DEST" ]]; then
        rm -rf "$DEST"
    fi
}
on_error() {
    printf '%s backup FAILED (commit %s)\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git_sha)" >> "$LOG_FILE"
    cleanup_partial
}
fail_backup() {
    err "$*"
    on_error
    exit 1
}
trap on_error ERR

log "GIMS backup $TS → $DEST"
mkdir -p "$DEST"

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
if ! compose ps --status running --services 2>/dev/null | grep -qx postgres; then
    log "postgres is not running; starting it (backups work with the app stopped)"
    compose up -d postgres
fi
wait_for "postgres is accepting connections" 60 2 postgres_ready \
    || fail_backup "postgres is not reachable; cannot take a backup"

step "pg_dump (custom format, no owners/privileges)"
compose exec -T postgres pg_dump \
    -U "$(db_user)" \
    -d "$(db_name)" \
    --format=custom \
    --compress=6 \
    --no-owner \
    --no-privileges \
    >"$DEST/db.dump" \
    || fail_backup "pg_dump failed"

db_size="$(stat -c '%s' "$DEST/db.dump")"
(( db_size > 1024 )) || fail_backup "db.dump is suspiciously small (${db_size} bytes)"

step "verifying db.dump with pg_restore --list"
compose exec -T postgres pg_restore --list <"$DEST/db.dump" >"$DEST/db.toc" \
    || fail_backup "pg_restore --list rejected db.dump (artifact is not restorable)"

if ! grep -qE '^[0-9]+; [0-9]+ [0-9]+ TABLE ' "$DEST/db.toc"; then
    fail_backup "db.toc contains no TABLE entries (empty or corrupt dump)"
fi

table_count="$(grep -cE '^[0-9]+; [0-9]+ [0-9]+ TABLE ' "$DEST/db.toc")"
data_count="$(grep -cE '^[0-9]+; [0-9]+ [0-9]+ TABLE DATA ' "$DEST/db.toc" || true)"
ok "database dump verified (${table_count} tables, ${data_count} data sections, ${db_size} bytes)"

# ---------------------------------------------------------------------------
# Private storage (uploaded intern documents and other app files)
# ---------------------------------------------------------------------------
storage_size=0
if (( DB_ONLY )); then
    warn "storage archive skipped (--db-only); a full restore needs it"
else
    step "archiving storage/app from the app container"
    compose run --rm --no-deps -T -u www-data app \
        tar -C /var/www/html/storage -czf - app \
        >"$DEST/storage.tar.gz" \
        || fail_backup "storage archive failed (is the gims-app image built?)"

    tar -tzf "$DEST/storage.tar.gz" >/dev/null \
        || fail_backup "storage.tar.gz failed the gzip/tar integrity check"

    storage_size="$(stat -c '%s' "$DEST/storage.tar.gz")"
    ok "storage archive verified (${storage_size} bytes)"
fi

# ---------------------------------------------------------------------------
# Manifest, checksums and the marker deploy.sh looks for
# ---------------------------------------------------------------------------
{
    printf 'timestamp=%s\n' "$TS"
    printf 'git_commit=%s\n' "$(git_sha)"
    printf 'database=%s\n' "$(db_name)"
    printf 'db_dump_bytes=%s\n' "$db_size"
    printf 'db_tables=%s\n' "$table_count"
    printf 'storage_included=%s\n' "$(( DB_ONLY ? 0 : 1 ))"
    printf 'storage_bytes=%s\n' "$storage_size"
    printf 'host=%s\n' "$(hostname 2>/dev/null || echo unknown)"
} >"$DEST/MANIFEST.txt"

if (( DB_ONLY )); then
    (cd "$DEST" && sha256sum db.dump >SHA256SUMS)
else
    (cd "$DEST" && sha256sum db.dump storage.tar.gz >SHA256SUMS)
fi

# Written only after every check above passed.
date -u +%Y-%m-%dT%H:%M:%SZ >"$BACKUP_MARKER"
ok "verified backup complete: $DEST ($(human_size "$DEST"))"

# Past this point the set is valid. A failure in retention or the off-host copy
# must never delete the artifacts or leave the marker pointing at nothing.
trap - ERR

# ---------------------------------------------------------------------------
# Retention (never prunes the newest BACKUP_KEEP_MIN backups)
# ---------------------------------------------------------------------------
if (( PRUNE )); then
    retention_days="${BACKUP_RETENTION_DAYS:-$(env_get BACKUP_RETENTION_DAYS)}"
    retention_days="${retention_days:-14}"
    keep_min="${BACKUP_KEEP_MIN:-$(env_get BACKUP_KEEP_MIN)}"
    keep_min="${keep_min:-7}"

    mapfile -t backups < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort)
    total="${#backups[@]}"
    prunable=$(( total - keep_min ))

    if (( prunable > 0 )); then
        log "Retention: keeping the newest $keep_min, pruning >${retention_days}d"
        for ((i = 0; i < prunable; i++)); do
            dir="${backups[$i]}"
            if [[ -n "$(find "$dir" -maxdepth 0 -mtime +"$retention_days" -print -quit)" ]]; then
                step "pruning $(basename "$dir")"
                rm -rf "$dir" || warn "could not prune $dir (check permissions)"
            fi
        done
    fi
fi

printf '%s backup OK db_bytes=%s storage_bytes=%s dest=%s\n' \
    "$TS" "$db_size" "$storage_size" "$DEST" >>"$LOG_FILE"

# ---------------------------------------------------------------------------
# Off-host copy
# ---------------------------------------------------------------------------
if [[ -n "${BACKUP_HOOK:-}" ]]; then
    log "Running BACKUP_HOOK"
    if bash -c "$BACKUP_HOOK"; then
        ok "BACKUP_HOOK succeeded"
    else
        warn "BACKUP_HOOK failed — the off-host copy is NOT up to date"
    fi
else
    warn "No BACKUP_HOOK set: copy $DEST off this machine (weekly at minimum)"
fi

printf '\nRestore with:\n  ./scripts/restore.sh --db %s' "$DEST/db.dump"
if (( DB_ONLY )); then
    printf '   # no storage archive in this set\n\n'
else
    printf ' --storage %s\n\n' "$DEST/storage.tar.gz"
fi
