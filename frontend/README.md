# GIMS Next.js frontend (Phase 3)

Next.js (App Router, TypeScript) frontend for the GIMS API. It talks to Laravel
through `/api/v1` using Sanctum session-cookie auth — no tokens are stored in
the browser.

## Status

Phase 3 has started. Implemented here:

| Area | Status |
|---|---|
| Project scaffold, env contract | Done |
| API client (CSRF, credentials, 419 retry, 422 errors) | Done |
| Auth provider + guest/force-password guards | Done |
| Login page (Blade split-panel design) | Done |
| Forced password change page | Done |
| App shell: topbar, role-aware sidebar, notification badge | Done |
| Dashboard (staff statistics, charts, expiring contracts, intern self-service panels) | Done |
| Interns: roster, detail, create, edit, archive/restore/permanent delete | Done |
| Intern documents: review list, folder browser, per-intern folder | Done |
| Intern self-service: Daily Time Record and 201 Files (upload + working-day picker) | Done |
| Reports (four CSV exports) and audit/activity log | Done |
| Settings: users, partner agencies, document types | Done |
| Profile (details + password) and notifications | Done |

Every authenticated Blade screen now has a Next.js route, so the catch-all
`src/app/not-found.tsx` only handles genuinely unknown URLs. Blade stays the
reference for visual/logic parity until Phase 6 acceptance and cutover.

## Local development

```bash
cp .env.example .env.local
npm install
npm run dev          # http://localhost:3000
```

Run the API on the **same host** as the app so the Sanctum session cookie is
same-site, and use a PHP binary that has `pdo_mysql` (on this workstation that
is XAMPP's `C:\xampp\php\php.exe`, not the `php` on `PATH`):

```bash
/c/xampp/php/php.exe artisan serve --host=localhost --port=8000
```

Laravel's `.env` needs the frontend origin allowed with credentials:

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:3000
CORS_SUPPORTS_CREDENTIALS=true
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000,localhost:8000,127.0.0.1:8000
```

`php artisan config:clear` after changing those. Then confirm the wiring:

```bash
node scripts/check-local-api.mjs http://localhost:8000 http://localhost:3000
```

It checks CORS (origin + credentials), the `XSRF-TOKEN` cookie, and that
unauthenticated API calls return JSON `401` rather than a login redirect.

Document previews and downloads are fetched with the session cookie and shown
from a blob URL, so private files keep working when the API is on another host.

## Production

- Deploy on Vercel with root directory `frontend`.
- `NEXT_PUBLIC_API_URL=https://api.example.com`
- Frontend host and API host must share the registrable domain
  (`app.example.com` / `api.example.com`), with
  `SESSION_DOMAIN=.example.com`.

Only `NEXT_PUBLIC_*` values are exposed to the browser. Never put database,
Meilisearch, tunnel, or Laravel secrets here.

## Styling parity

`public/css/{style,app,dashboard}.css` and the DOLE brand images are snapshots
of the Blade application's assets. They are copied (not shared) because Vercel
builds only this directory; re-sync them whenever the Blade styles change, and
delete them after cutover.
