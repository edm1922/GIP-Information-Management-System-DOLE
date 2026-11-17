import { check } from "k6";
import http from "k6/http";

import { FRONTEND_URL } from "./config.js";
import { duration, failures, requests } from "./metrics.js";

/**
 * Optional frontend checks against the deployed Next.js shell. Disabled by
 * default: pointing load at Vercel/Cloudflare means load-testing third-party
 * services, which the safety rules exclude. Enable deliberately with
 * ENABLE_FRONTEND_CHECKS=1 when the frontend itself is what you want to probe.
 */
export function checkFrontendShell() {
  const login = http.get(`${FRONTEND_URL}/login`, { tags: { endpoint: "frontend_login" } });
  duration.frontend_login.add(login.timings.duration);
  requests.frontend_login.add(1);
  if (!check(login, { "frontend /login: HTTP 200": (res) => res.status === 200 })) {
    failures.frontend_login.add(1, { status: String(login.status) });
  }

  const dashboard = http.get(`${FRONTEND_URL}/dashboard`, { tags: { endpoint: "frontend_dashboard" } });
  duration.frontend_dashboard.add(dashboard.timings.duration);
  requests.frontend_dashboard.add(1);
  if (!check(dashboard, { "frontend /dashboard: HTTP 200": (res) => res.status === 200 })) {
    failures.frontend_dashboard.add(1, { status: String(dashboard.status) });
  }
}
