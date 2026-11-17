import { fail, sleep } from "k6";

import { SUMMARY_TREND_STATS, getProfile } from "../config/profiles.js";
import { accountForVu, parseAccounts } from "../lib/accounts.js";
import * as api from "../lib/api.js";
import { clearSession, login, verifySession } from "../lib/auth.js";
import {
  DASHBOARD_YEAR,
  ENABLE_FRONTEND_CHECKS,
  PROFILE_NAME,
  SEARCH_TERM,
  THINK_TIME_MAX,
  THINK_TIME_MIN,
} from "../lib/config.js";
import { checkFrontendShell } from "../lib/frontend.js";
import { sessionRenewals } from "../lib/metrics.js";
import { handleSummary } from "../lib/report.js";

/**
 * Read-only user workflow:
 *   login (once per virtual user) -> verify session -> dashboard ->
 *   beneficiary directory -> beneficiary search -> beneficiary details ->
 *   batch (active) listing -> small think time.
 *
 * Each VU is pinned to its own account from accounts.json and keeps its own
 * cookie jar for the whole test.
 */
const rawAccounts = __ENV.ACCOUNTS_JSON
  ? __ENV.ACCOUNTS_JSON
  : open(__ENV.ACCOUNTS_FILE || "../accounts.json");
const accounts = parseAccounts(rawAccounts);
const profile = getProfile(PROFILE_NAME);

export const options = {
  scenarios: { gims_read_workflow: profile.scenario },
  thresholds: profile.thresholds,
  summaryTrendStats: SUMMARY_TREND_STATS,
  // k6 clears the cookie jar between iterations by default; these VUs must
  // keep their Sanctum session for the whole test.
  noCookiesReset: true,
};

export function setup() {
  if (profile.maxVus > accounts.length) {
    fail(
      `Profile "${PROFILE_NAME}" ramps to ${profile.maxVus} concurrent VUs but accounts.json only has ${accounts.length} accounts. ` +
        "Each virtual user needs its own account.",
    );
  }

  if (!/^https?:\/\//i.test(__ENV.API_BASE_URL || "http://127.0.0.1:8000")) {
    fail("API_BASE_URL must be an absolute http(s) URL.");
  }

  return { profile: PROFILE_NAME, accounts: accounts.length, script: "load-test" };
}

/** VU-local state: session, resolved account and the intern id used for details. */
let account = null;
let user = null;
let beneficiaryId = null;

function thinkTimeSeconds() {
  const spread = Math.max(0, THINK_TIME_MAX - THINK_TIME_MIN);
  return THINK_TIME_MIN + Math.random() * spread;
}

export default function () {
  if (!account) {
    account = accountForVu(accounts, __VU);
  }

  if (!user) {
    user = login(account);
    if (!user) {
      return;
    }
  } else if (!verifySession()) {
    // Session expired mid-test: transparently establish a fresh one.
    sessionRenewals.add(1);
    clearSession();
    user = login(account);
    if (!user) {
      return;
    }
  }

  api.getDashboard(DASHBOARD_YEAR);
  const directory = api.listBeneficiaries();
  api.searchBeneficiaries(SEARCH_TERM);

  if (!beneficiaryId) {
    beneficiaryId = api.extractBeneficiaryId(directory);
  }
  if (beneficiaryId) {
    api.getBeneficiary(beneficiaryId);
  }
  api.listBatches();

  if (ENABLE_FRONTEND_CHECKS) {
    checkFrontendShell();
  }

  sleep(thinkTimeSeconds());
}

export { handleSummary };
