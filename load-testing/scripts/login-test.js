import { fail, sleep } from "k6";

import { SUMMARY_TREND_STATS, getProfile } from "../config/profiles.js";
import { accountForVu, parseAccounts } from "../lib/accounts.js";
import { clearSession, login, verifySession } from "../lib/auth.js";
import { THINK_TIME_MAX, THINK_TIME_MIN } from "../lib/config.js";
import { handleSummary } from "../lib/report.js";

/**
 * Login-only test: every iteration starts from a clean cookie jar, authenticates
 * with the VU's own account and verifies the session.
 *
 * Pacing note: the API group is throttled at 120 requests/minute, and
 * unauthenticated requests (csrf-cookie + login) are keyed by client IP, so this
 * test is intended for the smoke/small profiles. Use load-test.js for medium
 * and target runs (it logs in once per VU).
 */
const rawAccounts = __ENV.ACCOUNTS_JSON
  ? __ENV.ACCOUNTS_JSON
  : open(__ENV.ACCOUNTS_FILE || "../accounts.json");
const accounts = parseAccounts(rawAccounts);
const profile = getProfile(__ENV.PROFILE || "smoke");

export const options = {
  scenarios: { gims_login: profile.scenario },
  thresholds: {
    // Only metrics this script actually emits are thresholded.
    checks: ["rate>0.98"],
    http_req_failed: ["rate<0.02"],
    gims_auth_failure_rate: ["rate<0.01"],
    gims_duration_login: [`p(95)<${Number(__ENV.THRESHOLD_P95_MS || 1500)}`],
    gims_duration_auth_user: [`p(95)<${Number(__ENV.THRESHOLD_P95_MS || 1500)}`],
  },
  summaryTrendStats: SUMMARY_TREND_STATS,
};

export function setup() {
  if (profile.maxVus > accounts.length) {
    fail(`Profile "${__ENV.PROFILE || "smoke"}" needs ${profile.maxVus} accounts but only ${accounts.length} are configured.`);
  }

  return { profile: __ENV.PROFILE || "smoke", accounts: accounts.length, script: "login-test" };
}

function loginPauseSeconds() {
  const spread = Math.max(0, THINK_TIME_MAX - THINK_TIME_MIN);
  // Slower than the read workflow on purpose: keeps csrf+login under the
  // IP-keyed 120 req/min throttle for small profiles.
  return 8 + THINK_TIME_MIN + Math.random() * (spread + 4);
}

export default function () {
  const account = accountForVu(accounts, __VU);

  clearSession();
  const user = login(account);
  if (user) {
    verifySession();
  }

  sleep(loginPauseSeconds());
}

export { handleSummary };
