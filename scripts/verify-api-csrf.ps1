# Verifies the real Sanctum SPA cookie flow (CSRF, session, 419) against the
# actual HTTP stack — the PHPUnit suite bypasses CSRF by design, so this is the
# only check that proves the browser-facing auth handshake works.
#
# Safety: this script NEVER touches the configured MySQL/PostgreSQL database.
# It forces DB_CONNECTION=sqlite and DB_DATABASE to a throwaway file under the
# temp directory, migrates that file, creates one test user, then deletes it.
#
# Usage (from the repository root):
#   powershell -ExecutionPolicy Bypass -File scripts/verify-api-csrf.ps1

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$port = 8123
$base = "http://127.0.0.1:$port"

$work = Join-Path $env:TEMP 'gips-csrf-verify'
if (Test-Path $work) { Remove-Item -Recurse -Force $work }
New-Item -ItemType Directory -Path $work | Out-Null

$dbPath = Join-Path $work 'csrf.sqlite'
New-Item -ItemType File -Path $dbPath | Out-Null

$jar = Join-Path $work 'cookies.txt'
$serverOut = Join-Path $work 'server.out.log'
$serverErr = Join-Path $work 'server.err.log'

if (-not $dbPath.StartsWith($env:TEMP)) {
    throw "Refusing to run: scratch database is not under the temp directory."
}

# ── Isolated environment (existing process env wins over .env) ───────────────
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = $base
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $dbPath
$env:SESSION_DRIVER = 'database'
$env:SESSION_DOMAIN = 'null'
$env:SESSION_SECURE_COOKIE = 'false'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'log'
$env:SANCTUM_STATEFUL_DOMAINS = "127.0.0.1:$port,localhost:$port"
$env:CORS_ALLOWED_ORIGINS = $base
$env:CORS_SUPPORTS_CREDENTIALS = 'true'
# On Windows, PHP's built-in server needs SystemRoot in its environment to bind.
# Laravel's `serve` reload mode strips every variable that is not in
# ServeCommand::$passthroughVariables (SystemRoot is not), which makes
# `php -S` fail with "Failed to listen ... (reason: ?)". The script therefore
# starts the server with --no-reload, which passes the full environment.
# Setting this to 1 also avoids the unsupported fork attempt (PHP is compiled
# without fork support on Windows).
$env:PHP_CLI_SERVER_WORKERS = '1'

$results = New-Object System.Collections.Generic.List[object]

function Record-Check([string]$name, [bool]$passed, [string]$detail = '') {
    $results.Add([pscustomobject]@{ Check = $name; Passed = $passed; Detail = $detail })
}

function Get-Json([string]$path) {
    if (-not (Test-Path $path)) { return $null }
    try { return Get-Content -Raw $path | ConvertFrom-Json } catch { return $null }
}

function Get-XsrfToken {
    $line = Get-Content $jar | Where-Object { $_ -match "`tXSRF-TOKEN`t" } | Select-Object -First 1
    if (-not $line) { return $null }
    $value = ($line -split "`t")[-1]
    return [uri]::UnescapeDataString($value)
}

try {
    Write-Host 'Migrating throwaway SQLite database...'
    $migrate = & php artisan migrate --force 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Migration failed:`n$migrate" }

    Write-Host 'Creating verification user...'
    $bootstrap = Join-Path $work 'bootstrap-user.php'
    $php = @"
<?php
require '$root/vendor/autoload.php';
`$app = require '$root/bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\App\Models\User::updateOrCreate(
    ['username' => 'csrf-verify'],
    [
        'first_name' => 'CSRF',
        'last_name' => 'Verify',
        'password' => \Illuminate\Support\Facades\Hash::make('CsrfVerify#2026'),
        'role' => 'admin',
        'is_active' => true,
        'must_change_password' => false,
    ]
);
echo 'USER_OK', PHP_EOL;
"@
    Set-Content -Path $bootstrap -Value $php -Encoding UTF8
    $userOut = & php $bootstrap 2>&1
    if ($LASTEXITCODE -ne 0 -or ($userOut -join "`n") -notmatch 'USER_OK') {
        throw "Could not create verification user:`n$userOut"
    }

    Write-Host 'Starting artisan serve...'
    $server = Start-Process -FilePath 'php' `
        -ArgumentList 'artisan', 'serve', '--no-reload', "--host=127.0.0.1", "--port=$port" `
        -PassThru -NoNewWindow `
        -RedirectStandardOutput $serverOut -RedirectStandardError $serverErr

    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        try {
            $probe = Invoke-WebRequest -Uri "$base/up" -UseBasicParsing -TimeoutSec 2
            if ($probe.StatusCode -eq 200) { $ready = $true; break }
        } catch {
            # not up yet
        }
    }
    if (-not $ready) {
        throw "Server did not become ready. Logs:`n$(Get-Content -Raw $serverErr -ErrorAction SilentlyContinue)"
    }

    # 1. CSRF cookie is issued.
    & curl.exe -s -c $jar -o (Join-Path $work 'csrf.out') "$base/sanctum/csrf-cookie" | Out-Null
    $xsrf = Get-XsrfToken
    Record-Check 'GET /sanctum/csrf-cookie issues XSRF-TOKEN' ($null -ne $xsrf)

    # 2. Stateful login succeeds with a matching token.
    $loginOut = Join-Path $work 'login.json'
    # Written to a file because PowerShell 5.1 strips embedded quotes from
    # native-command arguments, which would send invalid JSON to the API.
    $loginBodyFile = Join-Path $work 'login-request.json'
    Set-Content -Path $loginBodyFile -Value '{"username":"csrf-verify","password":"CsrfVerify#2026"}' -Encoding ASCII -NoNewline
    $status = & curl.exe -s -b $jar -c $jar -o $loginOut -w '%{http_code}' -X POST "$base/api/v1/auth/login" `
        -H 'Accept: application/json' `
        -H 'Content-Type: application/json' `
        -H "Origin: $base" `
        -H "Referer: $base/login" `
        -H "X-XSRF-TOKEN: $xsrf" `
        --data-binary "@$loginBodyFile"
    $login = Get-Json $loginOut
    $loginDetail = if ($status -eq '200') { "status=$status" } else { "status=$status body=$(Get-Content -Raw $loginOut -ErrorAction SilentlyContinue)" }
    Record-Check 'POST /api/v1/auth/login (valid token) -> 200' ($status -eq '200') $loginDetail
    Record-Check 'Login returns the authenticated user' ($login -and $login.user.username -eq 'csrf-verify') `
        ($(if ($login) { "username=$($login.user.username)" } else { 'no JSON body' }))

    # 3. Session cookie authenticates follow-up requests.
    # Sanctum's EnsureFrontendRequestsAreStateful only boots the session/cookie
    # middleware when the request carries an Origin/Referer that matches
    # SANCTUM_STATEFUL_DOMAINS. A real SPA always sends these, so the script
    # must too.
    $meOut = Join-Path $work 'me.json'
    $status = & curl.exe -s -b $jar -o $meOut -w '%{http_code}' "$base/api/v1/auth/user" `
        -H 'Accept: application/json' `
        -H "Origin: $base" `
        -H "Referer: $base/"
    $me = Get-Json $meOut
    Record-Check 'GET /api/v1/auth/user (session cookie) -> 200' ($status -eq '200') "status=$status"
    Record-Check 'Session returns the same user' ($me -and $me.user.username -eq 'csrf-verify')

    # 4. A tampered CSRF token is rejected with JSON 419.
    $csrfOut = Join-Path $work 'csrf-fail.json'
    $status = & curl.exe -s -b $jar -o $csrfOut -w '%{http_code}' -X POST "$base/api/v1/notifications/mark-all-read" `
        -H 'Accept: application/json' `
        -H "Origin: $base" `
        -H 'X-XSRF-TOKEN: definitely-not-the-token'
    $csrfBody = Get-Json $csrfOut
    Record-Check 'Tampered CSRF token -> 419' ($status -eq '419') "status=$status"
    Record-Check '419 response is JSON (not an HTML error page)' `
        ($null -ne $csrfBody -and $csrfBody.message -match 'CSRF') `
        ($(if ($csrfBody) { $csrfBody.message } else { 'no JSON body' }))

    # 5. Unauthenticated API calls are JSON 401 even without an Accept header.
    $anonOut = Join-Path $work 'anon.json'
    $status = & curl.exe -s -o $anonOut -w '%{http_code}' "$base/api/v1/dashboard" -H 'Accept: */*'
    $anon = Get-Json $anonOut
    Record-Check 'Unauthenticated API call without Accept -> 401' ($status -eq '401') "status=$status"
    Record-Check 'Unauthenticated response is JSON' ($anon -and $anon.message) `
        ($(if ($anon) { $anon.message } else { 'no JSON body' }))

    # 6. Logout invalidates the session. Re-read the token from the jar first:
    # a browser always uses the newest XSRF cookie the server sent.
    $xsrf = Get-XsrfToken
    $logoutOut = Join-Path $work 'logout.json'
    $status = & curl.exe -s -b $jar -c $jar -o $logoutOut -w '%{http_code}' -X POST "$base/api/v1/auth/logout" `
        -H 'Accept: application/json' `
        -H "Origin: $base" `
        -H "Referer: $base/" `
        -H "X-XSRF-TOKEN: $xsrf"
    $logoutDetail = if ($status -eq '200') { "status=$status" } else { "status=$status body=$(Get-Content -Raw $logoutOut -ErrorAction SilentlyContinue)" }
    Record-Check 'POST /api/v1/auth/logout -> 200' ($status -eq '200') $logoutDetail

    # After logout the same cookie must no longer authenticate. This needs the
    # stateful headers too, otherwise the endpoint is 401 for a different
    # reason (no session booted) and would not prove the session was destroyed.
    $status = & curl.exe -s -b $jar -o (Join-Path $work 'after-logout.json') -w '%{http_code}' `
        "$base/api/v1/auth/user" -H 'Accept: application/json' -H "Origin: $base" -H "Referer: $base/"
    Record-Check 'Session is invalid after logout -> 401' ($status -eq '401') "status=$status"
} finally {
    if ($server) {
        try { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue } catch {}
    }
    try {
        Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue |
            Select-Object -ExpandProperty OwningProcess -Unique |
            ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }
    } catch {}
}

Write-Host ''
$failed = 0
foreach ($result in $results) {
    $label = if ($result.Passed) { 'PASS' } else { 'FAIL' }
    if (-not $result.Passed) { $failed++ }
    Write-Host ("[{0}] {1} {2}" -f $label, $result.Check, $result.Detail)
}

Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue

if ($failed -gt 0) {
    Write-Host ''
    Write-Host "$failed check(s) failed."
    exit 1
}

Write-Host ''
Write-Host 'All CSRF/session checks passed.'
exit 0
