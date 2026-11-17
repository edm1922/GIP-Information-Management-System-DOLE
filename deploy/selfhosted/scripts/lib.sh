#!/usr/bin/env bash
# Shared helpers for the GIMS self-hosted deployment scripts.
#
# This file is sourced by preflight.sh / deploy.sh / backup.sh / restore.sh /
# verify.sh; it is not meant to be executed directly.

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"
ENV_FILE="$ROOT_DIR/.env"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/backups}"
BACKUP_MARKER="$ROOT_DIR/.last-verified-backup"

cd "$ROOT_DIR"

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    C_RED=$'\033[0;31m'
    C_GREEN=$'\033[0;32m'
    C_YELLOW=$'\033[0;33m'
    C_BLUE=$'\033[0;34m'
    C_DIM=$'\033[2m'
    C_RESET=$'\033[0m'
else
    C_RED='' C_GREEN='' C_YELLOW='' C_BLUE='' C_DIM='' C_RESET=''
fi

log()  { printf '%s==>%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
step() { printf '%s  ->%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
ok()   { printf '  %s[ok]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
err()  { printf '  %s[fail]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
die()  { err "$*"; exit 1; }

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

# ---------------------------------------------------------------------------
# .env access
#
# The file is parsed, never sourced: values must not be executed. If a key is
# defined more than once the LAST value wins, which matches both `docker
# compose` interpolation and the env_file precedence the container sees.
# ---------------------------------------------------------------------------
env_get() {
    local key="$1" value
    value="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" 2>/dev/null | tail -n 1 | sed -E "s/^[[:space:]]*${key}=[[:space:]]*//")" || true
    value="${value%$'\r'}"
    if [[ "$value" =~ ^\"(.*)\"$ ]]; then
        value="${BASH_REMATCH[1]}"
    elif [[ "$value" =~ ^\'(.*)\'$ ]]; then
        value="${BASH_REMATCH[1]}"
    fi
    printf '%s' "$value"
}

env_require() {
    local value
    value="$(env_get "$1")"
    [[ -n "$value" ]] || die "$1 is missing or empty in $ENV_FILE"
    printf '%s' "$value"
}

# Defaults used by the local verification helper.
host_port() {
    local port
    port="$(env_get NGINX_HOST_PORT)"
    printf '%s' "${port:-8080}"
}

db_user() { env_get DB_USERNAME; }
db_name() { env_get DB_DATABASE; }

require_env_file() {
    [[ -f "$ENV_FILE" ]] || die "missing $ENV_FILE — copy .env.production.example to .env and fill it in"
}

# ---------------------------------------------------------------------------
# Docker / Compose
# ---------------------------------------------------------------------------
compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

require_docker() {
    need_cmd docker
    docker compose version >/dev/null 2>&1 || die "docker compose v2 is required (docker-compose v1 is not supported)"
    docker info >/dev/null 2>&1 || die "cannot reach the Docker daemon (is it running? is this user in the 'docker' group?)"
}

# Run artisan inside the running app container as the same user PHP-FPM uses,
# so files created in the shared volumes stay writable by the web process.
app_artisan() {
    compose exec -T -u www-data app php artisan "$@"
}

postgres_ready() {
    compose exec -T postgres pg_isready -q -U "$(db_user)" -d "$(db_name)" >/dev/null 2>&1
}

meilisearch_ready() {
    compose exec -T app curl -fsS --max-time 5 http://meilisearch:7700/health >/dev/null 2>&1
}

nginx_ready() {
    curl -fsS --max-time 5 "http://127.0.0.1:$(host_port)/healthz" >/dev/null 2>&1
}

# wait_for <description> <attempts> <delay-seconds> <command...>
wait_for() {
    local description="$1" attempts="$2" delay="$3"
    shift 3
    local i
    for ((i = 1; i <= attempts; i++)); do
        if "$@" >/dev/null 2>&1; then
            ok "$description"
            return 0
        fi
        sleep "$delay"
    done
    err "timed out waiting for: $description"
    return 1
}

# ---------------------------------------------------------------------------
# Safety gates
# ---------------------------------------------------------------------------
confirm_typed() {
    local expected="$1" prompt="$2" answer
    [[ -t 0 ]] || die "an interactive terminal is required to confirm '$expected'"
    printf '\n%s\nType %s%s%s to continue: ' "$prompt" "$C_YELLOW" "$expected" "$C_RESET"
    read -r answer
    [[ "$answer" == "$expected" ]] || die "confirmation did not match — aborted"
    ok "confirmed"
}

require_migration_unlock() {
    [[ "$(env_get ALLOW_MIGRATIONS)" == "yes" ]] \
        || die "migrations are locked: set ALLOW_MIGRATIONS=yes in $ENV_FILE to unlock, then set it back to no"
}

# Refuses unless a *verified* backup (backup.sh wrote the marker only after
# pg_dump and tar integrity checks passed) is recent enough.
require_recent_verified_backup() {
    local max_hours="${1:-24}" marker_epoch now age
    [[ -f "$BACKUP_MARKER" ]] \
        || die "no verified-backup marker ($BACKUP_MARKER) — run ./scripts/backup.sh successfully first"
    marker_epoch="$(date -u -d "$(cat "$BACKUP_MARKER")" +%s 2>/dev/null || stat -c %Y "$BACKUP_MARKER")"
    now="$(date -u +%s)"
    (( marker_epoch <= now )) || die "the backup marker timestamp is in the future — fix the system clock first"
    age=$(( (now - marker_epoch) / 3600 ))
    (( age <= max_hours )) \
        || die "the last verified backup is ${age}h old (limit ${max_hours}h) — run ./scripts/backup.sh"
    ok "verified backup is ${age}h old (limit ${max_hours}h)"
}

# ---------------------------------------------------------------------------
# Misc
# ---------------------------------------------------------------------------
git_sha() {
    git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || printf 'unknown'
}

human_size() {
    du -sh "$1" 2>/dev/null | awk '{print $1}'
}
