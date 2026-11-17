#!/usr/bin/env bash
# Deploy (or update) the GIMS backend on this Docker host.
#
# Usage: ./scripts/deploy.sh [options]
#
#   --build              rebuild images (default behaviour, kept explicit)
#   --no-build           reuse the existing images (config/restart-only run)
#   --no-cache           rebuild without the Docker layer cache
#   --pull               pull newer base images before building
#   --migrate            apply pending migrations (gated: verified backup < 24h,
#                        ALLOW_MIGRATIONS=yes, typed confirmation)
#   --initialize         create the schema in a brand-new EMPTY database
#                        (refuses if any table already exists)
#   --seed-reference-data  with --initialize: seed companies, document types and
#                        the default accounts (change their passwords afterwards)
#   --skip-preflight     skip scripts/preflight.sh (not recommended)
#   --sync-search        sync Meilisearch index settings (opt-in: the current
#                        codebase has no Searchable models, so this normally fails)
#   -h, --help           show this help
#
# Exit codes: 0 success, 1 failure, 2 refused safety gate.

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

BUILD=1
NO_CACHE=0
PULL=0
MIGRATE=0
INITIALIZE=0
SEED_REFERENCE=0
SYNC_SEARCH=0
SKIP_PREFLIGHT=0

usage() {
    sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --build) BUILD=1 ;;
        --no-build) BUILD=0 ;;
        --no-cache) BUILD=1; NO_CACHE=1 ;;
        --pull) PULL=1 ;;
        --migrate) MIGRATE=1 ;;
        --initialize) INITIALIZE=1; MIGRATE=1 ;;
        --seed-reference-data) SEED_REFERENCE=1 ;;
        --sync-search) SYNC_SEARCH=1 ;;
        --skip-preflight) SKIP_PREFLIGHT=1 ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
    shift
done

trap 'err "command failed: ${BASH_COMMAND}"' ERR

log "GIMS deploy — $(date -u +%Y-%m-%dT%H:%M:%SZ) — commit $(git_sha)"
require_env_file

artisan_or_die() {
    app_artisan "$@" || die "'php artisan $*' failed — see: docker compose logs app"
}

# ---------------------------------------------------------------------------
# Safety gates first: refuse before spending time on a build.
# ---------------------------------------------------------------------------
if (( INITIALIZE )); then
    require_migration_unlock
    confirm_typed "INITIALIZE" "This creates the schema in a brand-new EMPTY database. It refuses to run if any table exists."
elif (( MIGRATE )); then
    require_migration_unlock
    require_recent_verified_backup 24
    confirm_typed "MIGRATE" "This applies pending migrations to the production database."
fi

if (( SKIP_PREFLIGHT )); then
    warn "preflight skipped (--skip-preflight)"
else
    log "Running preflight"
    "$SCRIPT_DIR/preflight.sh"
fi

# ---------------------------------------------------------------------------
# Build and start
# ---------------------------------------------------------------------------
if (( BUILD )); then
    build_args=()
    if (( NO_CACHE )); then build_args+=(--no-cache); fi
    if (( PULL )); then build_args+=(--pull); fi
    log "Building images"
    compose build "${build_args[@]}"
else
    log "Skipping build (--no-build)"
fi

log "Starting services"
compose up -d --remove-orphans

wait_for "postgres is accepting connections" 60 2 postgres_ready \
    || die "postgres did not become ready — docker compose logs postgres"

# ---------------------------------------------------------------------------
# Schema changes
# ---------------------------------------------------------------------------
if (( INITIALIZE )); then
    table_count="$(compose exec -T postgres psql -U "$(db_user)" -d "$(db_name)" -tAc \
        "select count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'" \
        | tr -d '[:space:]')"
    [[ "$table_count" == "0" ]] \
        || die "refusing to initialize: database '$(db_name)' already has ${table_count} table(s). Use --migrate with a verified backup."
    ok "database '$(db_name)' is empty"

    log "Creating the schema"
    artisan_or_die migrate --force

    if (( SEED_REFERENCE )); then
        log "Seeding reference data"
        artisan_or_die db:seed --class=CompanySeeder --force
        artisan_or_die db:seed --class=InternDocumentTypeSeeder --force
        artisan_or_die db:seed --class=UserSeeder --force
        warn "Default accounts were created (admin, peso_manager, juandc) using APP_PASSWORD_SUFFIX."
        warn "Change those passwords immediately after the first login."
    fi
elif (( MIGRATE )); then
    log "Applying migrations"
    artisan_or_die migrate --force
fi

# ---------------------------------------------------------------------------
# Production caches. Route caching is deliberately skipped: /api/v1/health is a
# closure route and cannot be serialized. Blade views are still served until
# cutover, so compile them too.
# ---------------------------------------------------------------------------
log "Caching configuration and views"
app_artisan config:cache || die "config:cache failed (check APP_KEY and .env)"
app_artisan view:cache || warn "view:cache failed; Blade views will compile on demand"

if (( SYNC_SEARCH )); then
    if wait_for "meilisearch is reachable from the app container" 15 2 meilisearch_ready; then
        app_artisan scout:sync-index-settings \
            || warn "scout:sync-index-settings failed; search filters/sorting may be degraded"
    else
        warn "meilisearch is not reachable; skipped index settings"
    fi
else
    step "Meilisearch index sync skipped (no Searchable models in the current codebase)"
fi

# ---------------------------------------------------------------------------
# Restart so PHP-FPM/workers load the new code and the cached config.
# ---------------------------------------------------------------------------
log "Restarting app, queue and scheduler"
compose restart app queue scheduler >/dev/null

wait_for "nginx is serving /healthz on 127.0.0.1:$(host_port)" 30 2 nginx_ready \
    || die "the stack did not become healthy — run ./scripts/verify.sh and docker compose logs"

printf '\n'
compose ps

log "Deploy complete (commit $(git_sha))"
cat <<EOF

  API origin (loopback) : http://127.0.0.1:$(host_port)
  Public API URL        : $(env_get APP_URL)
  Frontend URL          : $(env_get FRONTEND_URL)

Next steps
  ./scripts/verify.sh            # health, auth and exposure checks
  ./scripts/verify.sh --public   # also test the Cloudflare Tunnel hostname
  ./scripts/backup.sh            # take a verified backup now

EOF

if (( MIGRATE || INITIALIZE )); then
    warn "Schema changed: take a fresh verified backup (./scripts/backup.sh) and set ALLOW_MIGRATIONS=no"
fi
