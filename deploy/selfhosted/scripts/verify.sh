#!/usr/bin/env bash
# Verify a running GIMS self-hosted stack.
#
# Read-only end-to-end checks: container state, database, PHP-FPM/nginx, the
# Sanctum CSRF handshake, JSON error rendering, CORS and port exposure. With
# --public it also exercises the Cloudflare Tunnel hostname over HTTPS.
#
# Usage: ./scripts/verify.sh [--public] [--no-tunnel] [--help]
#
# Exit codes: 0 all checks passed, 1 one or more checks failed.

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PUBLIC=0
CHECK_TUNNEL=1

usage() { sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --public) PUBLIC=1 ;;
        --no-tunnel) CHECK_TUNNEL=0 ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
    shift
done

require_env_file
require_docker
need_cmd curl

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

FAIL=0
pass() { ok "$*"; }
fail() { err "$*"; FAIL=$((FAIL + 1)); }

# request <curl args...> → prints the HTTP status code, body in $TMP/body,
# headers in $TMP/headers.
request() {
    local code
    code="$(curl -sS --max-time 20 -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}' "$@" 2>"$TMP/curl.err")" || true
    printf '%s' "${code:-000}"
}

body_has() { grep -qi -- "$1" "$TMP/body"; }
header_has() { grep -qiE "^$1:.*$2" "$TMP/headers"; }

expect_code() {
    local actual="$1" expected="$2" label="$3"
    if [[ "$actual" == "$expected" ]]; then
        pass "$label ($actual)"
    else
        fail "$label: expected $expected, got $actual"
    fi
}

BASE="http://127.0.0.1:$(host_port)"

log "Verify: containers"
running="$(compose ps --status running --services 2>/dev/null || true)"
for svc in postgres meilisearch app queue scheduler nginx; do
    if grep -qx "$svc" <<<"$running"; then
        pass "$svc is running"
    else
        fail "$svc is not running"
    fi
done

if (( CHECK_TUNNEL )); then
    if grep -qx cloudflared <<<"$running"; then
        pass "cloudflared is running"
    else
        fail "cloudflared is not running (public access is down)"
    fi
fi

if command -v docker >/dev/null 2>&1; then
    for svc in queue scheduler; do
        cid="$(compose ps -q "$svc" 2>/dev/null || true)"
        if [[ -n "$cid" ]]; then
            restarts="$(docker inspect -f '{{.RestartCount}}' "$cid" 2>/dev/null || echo 0)"
            if (( restarts > 3 )); then
                fail "$svc restarted ${restarts}x (crash loop?) — docker compose logs $svc"
            else
                pass "$svc restart count is $restarts"
            fi
        fi
    done
fi

log "Verify: database"
if postgres_ready; then
    pass "pg_isready accepts connections"
else
    fail "pg_isready failed"
fi

if app_artisan migrate:status >/dev/null 2>&1; then
    pass "Laravel boots and reads the migrations table"
else
    fail "Laravel cannot read the database (check APP_KEY/.env: docker compose logs app)"
fi

pending="$(compose exec -T postgres psql -U "$(db_user)" -d "$(db_name)" -tAc 'select count(*) from jobs' 2>/dev/null | tr -d '[:space:]' || true)"
failed_jobs="$(compose exec -T postgres psql -U "$(db_user)" -d "$(db_name)" -tAc 'select count(*) from failed_jobs' 2>/dev/null | tr -d '[:space:]' || true)"
if [[ "$pending" =~ ^[0-9]+$ ]]; then
    if (( pending > 500 )); then
        fail "queue backlog is ${pending} jobs (worker stalled?)"
    else
        pass "queue backlog: ${pending} job(s)"
    fi
fi
if [[ "$failed_jobs" =~ ^[0-9]+$ ]] && (( failed_jobs > 0 )); then
    warn "${failed_jobs} failed job(s) in the database — inspect with: php artisan queue:failed"
fi

log "Verify: search"
if meilisearch_ready; then
    pass "Meilisearch answers /health from the app container"
else
    fail "Meilisearch is not reachable from the app container"
fi

log "Verify: HTTP (loopback origin)"
expect_code "$(request "$BASE/healthz")" 200 "GET /healthz"
expect_code "$(request "$BASE/up")" 200 "GET /up"

expect_code "$(request "$BASE/api/v1/health")" 200 "GET /api/v1/health"
if body_has '"status"[[:space:]]*:[[:space:]]*"ok"'; then
    pass "API health payload reports ok"
else
    fail "API health payload is unexpected: $(head -c 200 "$TMP/body")"
fi

# CSRF cookie handshake.
csrf_code="$(request -c "$TMP/jar" "$BASE/sanctum/csrf-cookie")"
if [[ "$csrf_code" == "204" || "$csrf_code" == "200" ]]; then
    pass "GET /sanctum/csrf-cookie ($csrf_code)"
else
    fail "GET /sanctum/csrf-cookie: expected 200/204, got $csrf_code"
fi
# Check the response header, not the cookie jar: SESSION_DOMAIN is a real
# registrable domain in production, so curl may refuse to store a cookie for
# .example.com when the request goes to 127.0.0.1. The header is what matters.
if grep -qi '^set-cookie:[[:space:]]*XSRF-TOKEN=' "$TMP/headers"; then
    pass "XSRF-TOKEN cookie is issued"
else
    fail "XSRF-TOKEN cookie was not issued"
fi

# Unauthenticated API calls must be JSON even without an Accept header.
code="$(request -H 'Accept: */*' "$BASE/api/v1/auth/user")"
expect_code "$code" 401 "GET /api/v1/auth/user unauthenticated"
if [[ "$code" == "401" ]] && body_has 'Unauthenticated'; then
    pass "unauthenticated response is JSON (no redirect to the Blade login)"
else
    fail "unauthenticated response is not the expected JSON error"
fi

# CORS preflight from the configured frontend origin.
frontend_origin="$(env_get FRONTEND_URL)"
code="$(request -X OPTIONS "$BASE/api/v1/auth/login" \
    -H "Origin: $frontend_origin" \
    -H 'Access-Control-Request-Method: POST' \
    -H 'Access-Control-Request-Headers: content-type,x-xsrf-token')"
if [[ "$code" == "204" || "$code" == "200" ]]; then
    pass "CORS preflight accepted ($code)"
else
    fail "CORS preflight returned $code"
fi
if header_has 'access-control-allow-origin' "${frontend_origin//./\\.}"; then
    pass "Access-Control-Allow-Origin matches $frontend_origin"
else
    fail "Access-Control-Allow-Origin is missing/mismatched for $frontend_origin"
fi
if header_has 'access-control-allow-credentials' 'true'; then
    pass "Access-Control-Allow-Credentials: true"
else
    fail "Access-Control-Allow-Credentials is not true (cookies will be blocked)"
fi

log "Verify: security headers"
request "$BASE/up" >/dev/null || true
for header in x-content-type-options x-frame-options referrer-policy strict-transport-security; do
    if header_has "$header" '.'; then
        pass "$header is present"
    else
        fail "$header is missing"
    fi
done

log "Verify: exposure"
if command -v ss >/dev/null 2>&1; then
    listening="$(ss -ltnH 2>/dev/null || ss -ltn 2>/dev/null || true)"
    for port in 5432 7700 9000; do
        if awk '{print $4}' <<<"$listening" | grep -qE "[:.]${port}$"; then
            fail "port $port is listening on the host (database/search/FPM must stay private)"
        else
            pass "port $port is not exposed on the host"
        fi
    done
    if awk '{print $4}' <<<"$listening" | grep -qE "^127\.0\.0\.1:$(host_port)$"; then
        pass "nginx is published on 127.0.0.1:$(host_port) only"
    else
        warn "nginx is not listening on 127.0.0.1:$(host_port) (custom bind?)"
    fi
else
    warn "'ss' not found; skipped host port checks"
fi

if (( PUBLIC )); then
    public_base="https://$(env_get APP_URL | sed -E 's#^https?://##' | cut -d/ -f1)"
    log "Verify: public endpoint ($public_base)"
    code="$(request "$public_base/api/v1/health")"
    expect_code "$code" 200 "GET $public_base/api/v1/health"
    if body_has '"status"[[:space:]]*:[[:space:]]*"ok"'; then
        pass "public API health payload reports ok"
    else
        fail "public API health payload is unexpected"
    fi
    if header_has 'strict-transport-security' 'max-age='; then
        pass "HSTS is served over the tunnel"
    else
        fail "HSTS header missing on the public endpoint"
    fi
    if header_has 'cf-ray' '.'; then
        pass "request went through Cloudflare (cf-ray present)"
    else
        warn "no cf-ray header; the hostname may not be proxied by Cloudflare"
    fi
fi

printf '\n'
if (( FAIL > 0 )); then
    err "$FAIL check(s) failed"
    exit 1
fi
ok "All verification checks passed"
