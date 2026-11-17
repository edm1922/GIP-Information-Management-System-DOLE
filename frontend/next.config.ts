import type { NextConfig } from "next";

/**
 * Origin that the SPA's API calls are proxied to.
 *
 * Sanctum needs the frontend and API to be same-site for the session cookie to
 * be first-party and for `document.cookie` to expose `XSRF-TOKEN`. When the API
 * lives on another domain (e.g. a `*.trycloudflare.com` tunnel), routing the
 * calls through this Next app makes every request same-origin for the browser.
 *
 * Read only when the build runs: rewrites are compiled into the build, so
 * changing this value requires a redeploy.
 */
const proxyTarget = (process.env.API_PROXY_TARGET ?? "").trim().replace(/\/+$/, "");

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
