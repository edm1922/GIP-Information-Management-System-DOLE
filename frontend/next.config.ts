import type { NextConfig } from "next";

const PLACEHOLDER_ENV_VALUES = new Set(["", "unset", "undefined", "null", "none", "false"]);

/**
 * Normalise an origin coming from the environment.
 *
 * A hosting dashboard variable is often left as a placeholder ("unset", "null",
 * …) while someone is switching it off. Passing that through would make the
 * rewrite destination `unset/api/v1/:path*` — a relative path that proxies back
 * into this app and 404s. Treat placeholders and non-absolute values as unset
 * and say so in the build log.
 */
function cleanTarget(name: string, value: string | undefined): string {
  const raw = (value ?? "").trim().replace(/\/+$/, "");
  if (PLACEHOLDER_ENV_VALUES.has(raw.toLowerCase())) {
    if (raw) console.warn(`[next.config] ${name}="${raw}" is a placeholder; ignoring it.`);
    return "";
  }
  if (!/^https?:\/\//i.test(raw)) {
    console.warn(`[next.config] ${name}="${raw}" is not an absolute http(s) URL; ignoring it.`);
    return "";
  }
  return raw;
}

/**
 * Origin that the SPA's API calls are proxied to.
 *
 * Sanctum needs the frontend and API to be same-site for the session cookie to
 * be first-party and for `document.cookie` to expose `XSRF-TOKEN`. When the API
 * lives on another domain (e.g. a Cloudflare tunnel), routing the calls through
 * this Next app makes every request same-origin for the browser.
 *
 * Read only when the build runs: rewrites are compiled into the build, so
 * changing this value requires a redeploy.
 */
const proxyTarget = cleanTarget("API_PROXY_TARGET", process.env.API_PROXY_TARGET);

// The API base baked into the client bundle has the same placeholder problem.
cleanTarget("NEXT_PUBLIC_API_URL", process.env.NEXT_PUBLIC_API_URL);

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,

  async rewrites() {
    if (!proxyTarget) return [];
    return [
      { source: "/api/v1/:path*", destination: `${proxyTarget}/api/v1/:path*` },
      { source: "/sanctum/csrf-cookie", destination: `${proxyTarget}/sanctum/csrf-cookie` },
    ];
  },
};

export default nextConfig;
