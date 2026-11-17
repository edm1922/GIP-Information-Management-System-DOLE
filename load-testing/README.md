# GIMS load testing (Grafana k6)

Isolated, read-only load-testing setup for the GIMS Laravel API. Nothing in this
directory is loaded by the application; no application code, routes, policies or
middleware are modified.

> **Safety:** every scenario performs GET requests only, after a normal login.
> No records are created, updated or deleted. Load is pointed at the local
> Laravel backend (`127.0.0.1:8000`) by default so Vercel/Cloudflare (third
> parties) are not part of the measured system. Only the **smoke** profile has
> been executed during setup; `small`, `medium` and `target` require the operator
> to approve and run them.

---

## 1. What was inspected (Phase 1 findings)

### Deployment path

| Piece | Finding |
|---|---|
| Frontend | Next.js SPA on Vercel (`https://gims-dole12.vercel.app`); `gip-information-management-system-d-dusky.vercel.app` 307-redirects to it |
| Backend | Laravel 12 on the local PC, reachable as `http://127.0.0.1:8000` (`php artisan serve`) |
| Public backend path | Cloudflare quick tunnel -> local Laravel; the URL rotates on every tunnel restart (stored in `storage/logs/cloudflare-url.txt`) |
| Proxy relation | The Vercel build proxies `/api/v1/*` and `/sanctum/csrf-cookie` via `API_PROXY_TARGET` (build-time) |
| Load-test target | **Local backend directly** (`API_BASE_URL`), because driving load through Vercel/Cloudflare would test third-party services and add their limits/timeouts to the results |

### Authentication mechanism

Sanctum **SPA cookie** auth (no bearer tokens):

1. `GET /sanctum/csrf-cookie` — starts the session and sets `XSRF-TOKEN` plus the
   session cookie (`gims_session`, database session driver).
2. `POST /api/v1/auth/login` with JSON `{"username": "...", "password": "..."}`
   and header `X-XSRF-TOKEN: <URL-decoded XSRF-TOKEN cookie value>`.
3. `GET /api/v1/auth/user` verifies the session; `POST /api/v1/auth/logout` ends it.

Required request headers for statefulness/CSRF:

- `Origin: http://127.0.0.1:8000` and `Referer: http://127.0.0.1:8000/`
  (`SANCTUM_STATEFUL_DOMAINS` includes `127.0.0.1:8000`)
- `Accept: application/json`
- `X-XSRF-TOKEN` on mutating requests only (the only mutating request in this
  suite is the login POST; everything afterwards is GET)

Accounts must have `must_change_password = false`, otherwise the
`ForcePasswordChange` middleware blocks API routes with `403 PASSWORD_CHANGE_REQUIRED`.

**k6 gotcha (already handled):** k6 clears each VU's cookie jar between
iterations by default. `scripts/load-test.js` sets `noCookiesReset: true` so a
virtual user keeps its Sanctum session for the whole test; `login-test.js`
clears the jar deliberately to exercise a fresh login every iteration.

### Endpoints used

| Operation | Request | Notes |
|---|---|---|
| Login | `POST /api/v1/auth/login` | `username`, `password`; 5 attempts/min per username+IP |
| Session check | `GET /api/v1/auth/user` | 401 marks a dead session |
| Dashboard | `GET /api/v1/dashboard?year=YYYY` | PESO managers receive intern statistics |
| Beneficiary listing | `GET /api/v1/interns?page=N` | paginated 15/page (`interns.data`, `meta`, `links`); each row carries `beneficiary` and `batch` |
| Beneficiary search | `GET /api/v1/interns?search=<term>` | searches first/last name and username |
| Beneficiary details | `GET /api/v1/interns/{id}` | 404 when the id is unknown; 403 for interns viewing others |
| Batch listing | `GET /api/v1/interns?status=active&page=1` | **GIMS has no dedicated batch endpoint.** `batch` is a column on intern rows (also exported in the CSV report); the active list is the closest read that surfaces batch/beneficiary data |

All of these are accessible to the `peso_manager` role (the provisioned test
accounts use that role so no admin-only surface is needed).

### Response formats / authorization

- JSON resources: `InternResource`, `DashboardController` payload; pagination is
  Laravel's `{data, links, meta}`.
- Policies enforced: `InternPolicy::viewAny/view` (admin + PESO manager),
  `InternDocumentPolicy` (reviewer screens).
- API group is throttled at **120 requests/minute** per authenticated user
  (`throttle:120,1`); login has its own **5 attempts/minute per username+IP**
  lockout, cleared on success.

### Rate-limit math used by the profiles

One read iteration = 6 API requests (`auth/user`, dashboard, list, search,
details, batch) plus, once per VU, csrf-cookie + login.

- Think time 3-5 s => ~6 requests / ~4 s = ~90 requests/min per VU, under 120/min.
- Target profile: 20 VUs logging in once during a 30 s ramp = ~40 unauthenticated
  requests (`csrf-cookie` + login) in 30 s = ~80/min from one IP, under the
  120/min group throttle.
- `login-test.js` logs in every iteration, so it is intentionally paced slowly
  (10-17 s between logins) and is meant for the **smoke/small** profiles.

---

## 2. Layout

```
load-testing/
  .env.example                     config template (copy to .env)
  accounts.example.json            document shape only; real file is generated
  accounts.json                    GENERATED, contains credentials, gitignored
  config/profiles.js               smoke / small / medium / target profiles + thresholds
  lib/config.js                    environment-driven configuration
  lib/accounts.js                  account list parsing/validation, per-VU assignment
  lib/auth.js                      CSRF + login + session verification
  lib/api.js                       read-only API helpers with per-endpoint metrics
  lib/frontend.js                  optional deployed-frontend checks
  lib/metrics.js                   per-endpoint trends/counters, auth failure signals
  lib/report.js                    Markdown + JSON report and console summary
  scripts/load-test.js             authenticated read workflow (default entry point)
  scripts/login-test.js            login-only test (fresh session each iteration)
  scripts/frontend-smoke.js        deployed Next.js shell test (third-party; opt-in)
  scripts/install-k6.ps1           portable k6 installer (no admin needed)
  scripts/run.ps1                  Windows runner (loads .env, picks profile)
  scripts/stop.ps1                 out-of-band stop
  provision/provision-test-accounts.php   create/reset/cleanup dedicated accounts
  results/                         GENERATED reports, gitignored
  .tools/k6.exe                    GENERATED by the installer, gitignored
```

---

## 3. Install (Windows)

```powershell
# 1. Start the local backend (this repo's launcher starts MySQL + Laravel + tunnel)
.\start-backend.bat

# 2. Install k6 (portable, no admin required)
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\install-k6.ps1

#    ... or, if you have winget:
winget install k6

# 3. Provision the 20 dedicated test accounts (local DB only)
C:\xampp\php\php.exe .\load-testing\provision\provision-test-accounts.php --count=20
```

The provisioning script:

- creates/refreshes 20 `peso_manager` users named `loadtest01`..`loadtest20`,
  active and not forced to rotate passwords;
- writes `load-testing/accounts.json` (gitignored) — passwords are **never**
  printed to the console or written outside that file;
- refuses to run when `APP_ENV=production` unless `--force` is passed;
- only touches usernames with the configured prefix (`--prefix=loadtest`).

Cleanup when finished:

```powershell
C:\xampp\php\php.exe .\load-testing\provision\provision-test-accounts.php --cleanup
```

Use the XAMPP PHP binary (`C:\xampp\php\php.exe`) if the `php` on PATH does not
have `pdo_mysql`.

---

## 4. Configuration

Copy `load-testing/.env.example` to `load-testing/.env` and adjust, or pass
values on the command line. Nothing security-sensitive belongs in `.env`.

| Variable | Default | Meaning |
|---|---|---|
| `API_BASE_URL` | `http://127.0.0.1:8000` | Laravel backend under test |
| `FRONTEND_URL` | `https://gims-dole12.vercel.app` | used only by frontend checks |
| `ENABLE_FRONTEND_CHECKS` | `0` | include the deployed shell in the read workflow (third-party load; off by default) |
| `PROFILE` | `smoke` | `smoke`, `small`, `medium`, `target` |
| `THINK_TIME_MIN` / `THINK_TIME_MAX` | `3` / `5` | seconds between iterations |
| `SEARCH_TERM` | `a` | search query used by the search step |
| `THRESHOLD_P95_MS` / `THRESHOLD_P99_MS` | `1500` / `3000` | thresholds |
| `ACCOUNTS_FILE` | `load-testing/accounts.json` | set automatically by `run.ps1` |
| `ACCOUNTS_JSON` | - | inline accounts JSON (CI), overrides the file |
| `RUN_ID` | timestamp | report file name |

`run.ps1` also creates `results/` before each run (k6 does not create directories).

---

## 5. Running the scenarios

| Scenario | Command | Load |
|---|---|---|
| Smoke (1 user) | `...\run.ps1 -Script load -Profile smoke` | 1 VU, ~30 s |
| Small (5 users) | `...\run.ps1 -Script load -Profile small` | ramp to 5, hold 60 s, ramp down |
| Medium (10 users) | `...\run.ps1 -Script load -Profile medium` | ramp to 10, hold 120 s, ramp down |
| Target (20 users) | `...\run.ps1 -Script load -Profile target` | ramp to 20 over 30 s, hold **240 s**, ramp down 30 s |
| Login only | `...\run.ps1 -Script login -Profile smoke` | fresh login every iteration |
| Frontend shell (opt-in) | `...\run.ps1 -Script frontend -Profile smoke` | Vercel `/login` + `/dashboard` |

Full command form (from the repository root):

```powershell
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 -Script load -Profile smoke
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 -Script load -Profile small
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 -Script load -Profile medium
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 -Script load -Profile target
```

Override URLs ad hoc:

```powershell
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 `
  -Script load -Profile smoke -ApiBaseUrl http://127.0.0.1:8000
```

Each VU is pinned to its own account (`VU n -> account n`); a profile that ramps
higher than the number of configured accounts fails fast in `setup()` instead of
silently reusing a session.

**Stop the test**

- `Ctrl+C` in the k6 terminal (graceful), or
- `powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\stop.ps1`
  (kills `k6.exe` out of band).

---

## 6. Reading the results

Every run writes to `load-testing/results/`:

- `<RUN_ID>-report.md` — headline metrics, per-endpoint table, failed requests
- `<RUN_ID>-summary.json` — full raw k6 metrics
- console summary printed by the run

Key metrics:

| Metric | Meaning |
|---|---|
| `http_req_failed` | share of non-2xx/3xx responses |
| `http_req_duration` p(95)/p(99) | overall latency tail |
| `gims_duration_<endpoint>` | per-operation latency (dashboard, list, search, details, batch, login, auth/user) |
| `gims_requests_<endpoint>` | request volume per operation |
| `gims_failures_<endpoint>` | failed requests per operation |
| `gims_auth_failures` / `gims_auth_failure_rate` | logins that returned non-200 or no user |
| `gims_session_renewals` | sessions that expired mid-run and were re-established |
| `checks` | assertion pass rate |

Thresholds (defaults): failed requests < 2 %, checks > 98 %, p95 < 1500 ms,
p99 < 3000 ms, auth failures < 1 %. k6 exits non-zero if any threshold is
crossed, which makes the runner usable in CI.

**Before comparing runs**, keep these constant: profile, `THINK_TIME_*`, data
volume, Laravel cache state, PHP runtime (CLI server vs FPM), machine load.

---

## 7. Identifying backend bottlenecks

Read the per-endpoint table first, then use these known GIMS/runtime facts:

1. **`php artisan serve` on Windows is effectively single-threaded.** The PHP
   built-in server handles one request at a time (no `fork`), so under 20 VUs
   latency will mostly measure queueing, not Laravel. For a meaningful
   concurrency test, serve the app through Apache/PHP-FPM (e.g. a XAMPP virtual
   host pointed at `public/`) and set `API_BASE_URL` to it.
2. **Uncached dev configuration.** `APP_ENV=local`, debug on, and no
   `php artisan optimize` mean config/routes/views are re-evaluated per request.
   Measure production-like builds by caching config/routes before a run (this
   changes only runtime caches, not code).
3. **Database is on the hot path.** `/api/v1/dashboard` runs several aggregate
   queries; `/api/v1/interns` eager-loads `user` + `partnerAgency`.
   - Compare `gims_duration_dashboard` against `gims_duration_interns_list`.
   - Watch for N+1 queries (Laravel Debugbar / `DB::listen` / MySQL slow log) if
     a list endpoint scales with page size.
   - `EXPLAIN` the dashboard aggregates and check indexes on `interns.status`,
     `interns.contract_end_date`, `interns.partner_agency_id`.
4. **Throttling vs. capacity.** 429s mean the 120 req/min throttle was hit, not
   that the backend is exhausted. Adjust think time (README section 1) or raise
   the limit only in a dedicated test environment.
5. **Session store.** Sessions/cache use the database driver here; 20 sessions
   is trivial, but the `sessions` table is written on every request - when
   scaling users, move sessions/cache to Redis and compare.
6. **Network path.** If `API_BASE_URL` points at the Cloudflare tunnel or Vercel
   proxy, latency and 100 s tunnel limits dominate. Keep load on localhost.

Suggested next steps once smoke is stable: run `small`, watch p95 growth vs VUs;
if p95 grows linearly with VUs while CPU is low, it is the single-threaded dev
server; if CPU is high, profile Laravel/MySQL before changing thresholds.

---

## 8. Hitting the target scenario (requires approval)

The brief asks for 20 concurrent users for 3-5 minutes. The `target` profile
implements that (30 s ramp up, 240 s hold, 30 s ramp down). Do not run it until:

- the backend is served by Apache/PHP-FPM (see bottleneck #1), and
- the operator has explicitly approved the load window.

Command:

```powershell
powershell -ExecutionPolicy Bypass -File .\load-testing\scripts\run.ps1 -Script load -Profile target
```

---

## 9. Baseline runs on this machine

### Smoke (1 VU, 30 s)

| Metric | Value |
|---|---|
| Requests | 31 (0.91 req/s) |
| Iterations | 5 |
| Failed requests | 0.00 % |
| Response avg / p(95) / p(99) | 373 ms / 420 ms / 618 ms |
| Checks | 100 % |
| Auth failures | 0 |

Per endpoint (avg / p95): login 697/697 ms, auth/user 385/431 ms, dashboard
366/393 ms, interns list 368/376 ms, search 369/377 ms, details 344/350 ms,
batch list 357/366 ms.

### Target (20 VUs: 30 s ramp, 4 min hold, 30 s ramp-down)

| Metric | Value |
|---|---|
| Requests | 791 (2.56 req/s) |
| Iterations | 118 completed (17 interrupted at ramp-down) |
| Failed requests | 0.00 % (0 of 791) |
| Response avg / p(95) / p(99) | 6391 ms / 7809 ms / 8404 ms |
| Checks | 100 % |
| Auth failures / session renewals | 0 / 0 |

Per endpoint (requests, avg, p95): login 20 / 5044 / 8548 ms, auth/user 115 /
6490 / 7555 ms, dashboard 268 / 6319 / 7615 ms, interns list 262 / 6482 /
7647 ms, search 256 / 6547 / 7550 ms, details 244 / 6591 / 7607 ms, batch list
242 / 6525 / 7616 ms.

**Interpretation:** the backend stayed correct (no errors, no auth failures,
100 % checks) but saturated at ~2.56 req/s. Since `http_req_waiting` is
effectively the whole duration (client connect/blocked time is ~1 ms), the
latency is server-side queueing, not networking. 2.56 req/s matches roughly
`1 / 390 ms` - the per-request service time seen in the smoke run - which is the
signature of the **single-threaded `php artisan serve`** process: 20 concurrent
readers simply queue behind one PHP worker. Thresholds on latency were
therefore crossed, as expected.

Re-run the same profile behind Apache/PHP-FPM (and with config/routes cached)
before drawing any conclusion about the application code itself; that is the
apples-to-apples comparison for the target scenario.
## 10. Safety compliance

- Read-only requests only; no beneficiary/intern/document records are modified.
- Dedicated `loadtest*` accounts only; provisioning is opt-in and cleanable.
- Credentials live in `load-testing/accounts.json` (gitignored), fetched from
  environment/file at runtime; never printed or committed.
- Authentication, authorization and CSRF stay enabled - the scripts perform the
  real Sanctum flow.
- Third parties (Vercel/Cloudflare) are excluded by default; the frontend script
  is opt-in.
- Clear stop command: `Ctrl+C` or `scripts/stop.ps1`.
- No high-volume run is executed without explicit approval; only `smoke` was run
  during setup.

---

## 11. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `Accounts file is not valid JSON` | `accounts.json` missing or was opened in binary mode; re-run the provisioner |
| `auth/user` 401 on every iteration | cookie jar reset between iterations - keep `noCookiesReset: true` |
| login 419 | missing/expired `X-XSRF-TOKEN`; request the CSRF cookie first |
| login 422 with "throttle" | 5 failed attempts/min per username+IP; wait or use fresh accounts |
| 429 on read endpoints | 120 req/min throttle exceeded; raise think time |
| 403 `PASSWORD_CHANGE_REQUIRED` | test account has `must_change_password=true`; re-run the provisioner |
| `k6 not found` | run `scripts/install-k6.ps1` or install via winget |
| Reports missing | run through `run.ps1` (creates `results/`), or create the folder manually |
