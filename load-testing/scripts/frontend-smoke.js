import http from "k6/http";
import { fail, sleep } from "k6";

import { SUMMARY_TREND_STATS, getProfile } from "../config/profiles.js";
import { FRONTEND_URL, PROFILE_NAME } from "../lib/config.js";
import { duration, failures, requests } from "../lib/metrics.js";
import { handleSummary } from "../lib/report.js";

/**
 * Frontend-shell smoke test against the deployed Next.js app (Vercel).
 *
 * This deliberately loads a THIRD-PARTY service (Vercel/Cloudflare) and is
 * therefore a separate, explicitly-run script - the backend load tests never
 * touch it. Keep the profile at smoke/small.
 */
const profile = getProfile(PROFILE_NAME);

export const options = {
  scenarios: { gims_frontend_smoke: profile.scenario },
  thresholds: {
    checks: ["rate>0.98"],
    http_req_failed: ["rate<0.02"],
    gims_duration_frontend_login: [`p(95)<${Number(__ENV.THRESHOLD_P95_MS || 2000)}`],
    gims_duration_frontend_dashboard: [`p(95)<${Number(__ENV.THRESHOLD_P95_MS || 2000)}`],
  },
  summaryTrendStats: SUMMARY_TREND_STATS,
};

export function setup() {
  if (!/^https?:\/\//i.test(FRONTEND_URL)) {
    fail("FRONTEND_URL must be an absolute http(s) URL.");
  }
  return { profile: PROFILE_NAME, frontend: FRONTEND_URL };
}

function fetch(endpoint, path) {
  const response = http.get(`${FRONTEND_URL}${path}`, {
    headers: { Accept: "text/html" },
    tags: { endpoint: endpoint },
  });

  duration[endpoint].add(response.timings.duration);
  requests[endpoint].add(1);

  const ok = response.status >= 200 && response.status < 300;
  if (!ok) {
    failures[endpoint].add(1, { status: String(response.status) });
  }

  return ok;
}

export default function () {
  fetch("frontend_login", "/login");
  fetch("frontend_dashboard", "/dashboard");
  sleep(2 + Math.random() * 3);
}

export { handleSummary };
