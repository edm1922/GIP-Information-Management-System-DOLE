#!/usr/bin/env bash
# Preflight checks for the GIMS self-hosted stack.
#
# Read-only: verifies the host, .env and Compose file before anything is built
# or started. Exit code 0 means "safe to run deploy.sh".
#
# Usage: ./scripts/preflight.sh [--help]

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    sed -n '2,7p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
fi

FAIL=0
pass() { ok "$*"; }
fail() { err "$*"; FAIL=$((FAIL + 1)); }

expect_eq() {
    local actual="$1" expected="$2" label="$3"
    if [[ "$actual" == "$expected" ]]; then
        pass "$label ($actual)"
    else
        fail "$label: expected '$expected', got '${actual:-<empty>}'"
    fi
}

expect_https() {
    local value="$1" label="$2"
    if [[ "$value" == https://* ]]; then
        pass "$label is HTTPS ($value)"
    else
        fail "$label must be an https:// URL (got '${value:-<empty>}')"
    fi
}

url_host() {
    local url="$1"
    url="${url#http://}"
    url="${url#https://}"
    printf '%s' "${url%%/*}"
}

log "Preflight: host"
require_docker
need_cmd curl
ok "docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo 'daemon reachable')"

ram_mb="$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo 2>/dev/null || echo 0)"
if (( ram_mb > 0 && ram_mb < 3500 )); then
    warn "only ${ram_mb} MB RAM detected; PHP-FPM (20 workers) + PostgreSQL + Meilisearch prefer >= 4 GB"
else
    pass "RAM: ${ram_mb:-unknown} MB"
fi

avail_kb="$(df -Pk "$ROOT_DIR" | awk 'NR == 2 {print $4}')"
if (( avail_kb < 5 * 1024 * 1024 )); then
    fail "less than 5 GB free disk on $ROOT_DIR (backups need headroom)"
elif (( avail_kb < 10 * 1024 * 1024 )); then
    warn "only $(( avail_kb / 1024 / 1024 )) GB free disk; keep room for database dumps"
else
    pass "free disk: $(( avail_kb / 1024 / 1024 )) GB"
fi

log "Preflight: environment file"
require_env_file
env_mode="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '???')"
if [[ "$env_mode" == "600" || "$env_mode" == "400" ]]; then
    pass ".env permissions are $env_mode"
else
    warn ".env permissions are $env_mode; run: chmod 600 .env"
fi

app_key="$(env_get APP_KEY)"
if [[ "$app_key" == base64:* && ${#app_key} -ge 44 ]]; then
    pass "APP_KEY is set"
else
    fail "APP_KEY is missing or not in the 'base64:...' format (openssl rand -base64 32)"
fi

expect_eq "$(env_get APP_ENV)" "production" "APP_ENV"
expect_eq "$(env_get APP_DEBUG)" "false" "APP_DEBUG"

app_url="$(env_get APP_URL)"
frontend_url="$(env_get FRONTEND_URL)"
expect_https "$app_url" "APP_URL"
expect_https "$frontend_url" "FRONTEND_URL"

log "Preflight: database"
expect_eq "$(env_get DB_CONNECTION)" "pgsql" "DB_CONNECTION"
expect_eq "$(env_get DB_HOST)" "postgres" "DB_HOST"
expect_eq "$(env_get DB_PORT)" "5432" "DB_PORT"

db_password="$(env_get DB_PASSWORD)"
if [[ -z "$db_password" ]]; then
    fail "DB_PASSWORD is empty"
elif [[ ${#db_password} -lt 12 ]]; then
    fail "DB_PASSWORD is shorter than 12 characters"
else
    pass "DB_PASSWORD is set (${#db_password} characters)"
fi
if [[ "$db_password" == *'$'* ]]; then
    warn "DB_PASSWORD contains '\$'; Compose interpolates it — escape as '\$\$' or avoid it"
fi

sslmode="$(env_get DB_SSLMODE)"
if [[ "$sslmode" == "require" || "$sslmode" == "verify-ca" || "$sslmode" == "verify-full" ]]; then
    fail "DB_SSLMODE=$sslmode will fail inside the Compose network (no TLS between containers); use 'prefer'"
else
    pass "DB_SSLMODE=${sslmode:-prefer}"
fi

log "Preflight: session, Sanctum and CORS"
expect_eq "$(env_get SESSION_DRIVER)" "database" "SESSION_DRIVER"
expect_eq "$(env_get SESSION_SECURE_COOKIE)" "true" "SESSION_SECURE_COOKIE"
expect_eq "$(env_get QUEUE_CONNECTION)" "database" "QUEUE_CONNECTION"
expect_eq "$(env_get CACHE_STORE)" "database" "CACHE_STORE"
expect_eq "$(env_get FILESYSTEM_DISK)" "local" "FILESYSTEM_DISK"

stateful="$(env_get SANCTUM_STATEFUL_DOMAINS)"
frontend_host="$(url_host "$frontend_url")"
if [[ -z "$stateful" ]]; then
    fail "SANCTUM_STATEFUL_DOMAINS is empty — login will return 401 for the SPA"
elif [[ "$stateful" == *'*'* ]]; then
    fail "SANCTUM_STATEFUL_DOMAINS must list explicit hosts, not '*'"
elif [[ "$stateful" != *"$frontend_host"* ]]; then
    fail "SANCTUM_STATEFUL_DOMAINS does not include the frontend host '$frontend_host'"
else
    pass "SANCTUM_STATEFUL_DOMAINS includes $frontend_host"
fi
if [[ "$stateful" == *"://"* ]]; then
    fail "SANCTUM_STATEFUL_DOMAINS must be host[:port] values, not URLs"
fi

cors="$(env_get CORS_ALLOWED_ORIGINS)"
if [[ -z "$cors" ]]; then
    fail "CORS_ALLOWED_ORIGINS is empty"
elif [[ "$cors" == *'*'* ]]; then
    fail "CORS_ALLOWED_ORIGINS must not contain '*' when credentials are enabled"
elif [[ "${cors%/}" != *"${frontend_url%/}"* ]]; then
    fail "CORS_ALLOWED_ORIGINS does not include FRONTEND_URL ($frontend_url)"
else
    pass "CORS_ALLOWED_ORIGINS includes $frontend_url"
fi

session_domain="$(env_get SESSION_DOMAIN)"
if [[ -z "$session_domain" || "$session_domain" == "null" ]]; then
    warn "SESSION_DOMAIN is not set: only correct when one host serves both the SPA and the API. Cross-subdomain (app.example.com + api.example.com) needs SESSION_DOMAIN=.example.com"
else
    pass "SESSION_DOMAIN=$session_domain"
fi

if [[ "$(env_get TRUSTED_PROXIES)" == *'*'* ]]; then
    pass "TRUSTED_PROXIES trusts the tunnel/proxy headers"
else
    warn "TRUSTED_PROXIES should include '*' behind the Cloudflare Tunnel, otherwise generated URLs and secure cookies may use http"
fi

log "Preflight: search"
meili_key="$(env_get MEILISEARCH_KEY)"
if [[ ${#meili_key} -lt 16 ]]; then
    fail "MEILISEARCH_KEY must be at least 16 characters (Meilisearch refuses to start otherwise)"
else
    pass "MEILISEARCH_KEY is set (${#meili_key} characters)"
fi
if [[ "$meili_key" == *'$'* ]]; then
    warn "MEILISEARCH_KEY contains '\$'; Compose interpolates it — escape as '\$\$' or avoid it"
fi
if [[ "$(env_get SCOUT_DRIVER)" == "meilisearch" ]]; then
    expect_eq "$(env_get MEILISEARCH_HOST)" "http://meilisearch:7700" "MEILISEARCH_HOST"
else
    warn "SCOUT_DRIVER is '$(env_get SCOUT_DRIVER)'; search will not use the Meilisearch container"
fi

log "Preflight: Cloudflare Tunnel"
tunnel_token="$(env_get CLOUDFLARE_TUNNEL_TOKEN)"
if [[ -n "$tunnel_token" ]]; then
    pass "CLOUDFLARE_TUNNEL_TOKEN is set (remotely-managed tunnel)"
    if [[ "$tunnel_token" == *'$'* ]]; then
        warn "CLOUDFLARE_TUNNEL_TOKEN contains '\$'; escape it as '\$\$' in .env"
    fi
elif [[ -f "$ROOT_DIR/cloudflared/config.yml" ]]; then
    pass "cloudflared/config.yml found (locally-managed tunnel)"
    if grep -q 'REPLACE-WITH' "$ROOT_DIR/cloudflared/config.yml"; then
        fail "cloudflared/config.yml still contains REPLACE-WITH placeholders"
    fi
    if ! ls "$ROOT_DIR"/cloudflared/*.json >/dev/null 2>&1; then
        fail "cloudflared/config.yml exists but no tunnel credentials JSON is present"
    fi
else
    fail "no tunnel configured: set CLOUDFLARE_TUNNEL_TOKEN or provide cloudflared/config.yml"
fi

log "Preflight: Compose file"
compose_config=""
if compose_config="$(compose config 2>"$ROOT_DIR/.preflight-compose.err")"; then
    pass "docker compose config is valid"
else
    fail "docker compose config failed:"
    sed 's/^/      /' "$ROOT_DIR/.preflight-compose.err" >&2
fi
rm -f "$ROOT_DIR/.preflight-compose.err"

# Validate the *resolved* config rather than the raw YAML: `compose config`
# expands short port syntax and interpolation, so comments or a reformatted
# file cannot hide a non-loopback binding. Any published port without a
# loopback host_ip would expose the origin (or the database) to the network.
if [[ -n "$compose_config" ]]; then
    published_count="$(grep -cE '^[[:space:]]*published:' <<<"$compose_config" || true)"
    loopback_count="$(grep -cE '^[[:space:]]*host_ip:[[:space:]]*"?127\.0\.0\.1"?[[:space:]]*$' <<<"$compose_config" || true)"
    non_loopback="$(grep -E '^[[:space:]]*host_ip:' <<<"$compose_config" | grep -vE '127\.0\.0\.1|::1' || true)"

    if [[ -n "$non_loopback" ]]; then
        fail "Compose publishes a port on a non-loopback address:"
        printf '%s\n' "$non_loopback" | sed 's/^/      /' >&2
    elif (( published_count != loopback_count )); then
        fail "${published_count} published port(s) but only ${loopback_count} bind to 127.0.0.1 (an unbound host_ip means all interfaces)"
    else
        pass "all ${published_count} published port(s) bind to 127.0.0.1"
    fi
fi

if git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [[ -z "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
        pass "working tree is clean (commit $(git_sha))"
    else
        warn "working tree has uncommitted changes; the deployed image will not match commit $(git_sha)"
    fi
fi

if [[ "$(env_get ALLOW_MIGRATIONS)" == "yes" ]]; then
    warn "ALLOW_MIGRATIONS=yes is set; set it back to 'no' once the migration is done"
fi

printf '\n'
if (( FAIL > 0 )); then
    err "$FAIL preflight check(s) failed — fix them before deploying"
    exit 1
fi
ok "All preflight checks passed"
