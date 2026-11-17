#!/usr/bin/env node
/**
 * Verifies the local Laravel API is ready for the Next.js SPA:
 *   1. CORS allows the frontend origin *with credentials*.
 *   2. Sanctum issues the XSRF-TOKEN cookie for that origin.
 *   3. The versioned API answers, and unauthenticated calls get JSON 401
 *      (never a redirect to the Blade login page).
 *
 * Usage:
 *   node scripts/check-local-api.mjs [apiUrl] [frontendOrigin]
 *   node scripts/check-local-api.mjs http://localhost:8000 http://localhost:3000
 */

const apiUrl = (process.argv[2] ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/+$/, "");
const origin = (process.argv[3] ?? "http://localhost:3000").replace(/\/+$/, "");

const failures = [];

function report(ok, label, detail = "") {
  console.log(`${ok ? "PASS" : "FAIL"}  ${label}${detail ? ` — ${detail}` : ""}`);
  if (!ok) failures.push(label);
}

function warnWhenCrossHost() {
  const apiHost = new URL(apiUrl).hostname;
  const originHost = new URL(origin).hostname;
  if (apiHost !== originHost) {
    console.log(
      `NOTE  API host (${apiHost}) differs from the app host (${originHost}). ` +
        "Sanctum's SameSite=Lax session cookie will be treated as third-party and may be dropped by the browser.",
    );
  }
}

async function main() {
  console.log(`API ${apiUrl}  ←  origin ${origin}\n`);
  warnWhenCrossHost();

  const health = await fetch(`${apiUrl}/api/v1/health`, {
    headers: { Accept: "application/json", Origin: origin },
  });
  report(health.status === 200, "API reachable (/api/v1/health)", `HTTP ${health.status}`);

  const csrf = await fetch(`${apiUrl}/sanctum/csrf-cookie`, {
    headers: { Accept: "application/json", Origin: origin },
  });
  const allowOrigin = csrf.headers.get("access-control-allow-origin");
  const allowCredentials = csrf.headers.get("access-control-allow-credentials");
  const cookies = csrf.headers.getSetCookie?.() ?? [];

  report(allowOrigin === origin, "CORS allows the frontend origin", allowOrigin ?? "no Access-Control-Allow-Origin header");
  report(allowCredentials === "true", "CORS allows credentials", allowCredentials ?? "missing header");
  report(
    cookies.some((cookie) => cookie.startsWith("XSRF-TOKEN=")),
    "Sanctum issued the XSRF-TOKEN cookie",
    cookies.length ? `${cookies.length} cookie(s)` : "no Set-Cookie",
  );

  const anonymous = await fetch(`${apiUrl}/api/v1/auth/user`, {
    headers: { Accept: "application/json", Origin: origin },
  });
  const contentType = anonymous.headers.get("content-type") ?? "";
  report(anonymous.status === 401, "Unauthenticated API call returns 401", `HTTP ${anonymous.status}`);
  report(contentType.includes("application/json"), "401 is JSON, not a login redirect", contentType || "no content type");

  if (failures.length) {
    console.log(
      "\nFix: set CORS_ALLOWED_ORIGINS, CORS_SUPPORTS_CREDENTIALS=true and " +
        "SANCTUM_STATEFUL_DOMAINS in the Laravel .env, then run `php artisan config:clear`.",
    );
    process.exit(1);
  }

  console.log("\nLocal API is ready for the SPA.");
}

main().catch((error) => {
  console.error(`ERROR ${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
});
