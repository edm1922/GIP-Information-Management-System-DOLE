/** Runtime configuration, entirely environment driven (no hardcoded secrets). */

function env(name, fallback) {
  const value = __ENV[name];
  return value === undefined || value === "" ? fallback : value;
}

function trimTrailingSlash(value) {
  return String(value).replace(/\/+$/, "");
}

function defaultRunId() {
  return new Date().toISOString().replace(/[:.]/g, "-");
}

/** Laravel backend under test. Default targets the local dev server. */
export const API_BASE_URL = trimTrailingSlash(env("API_BASE_URL", "http://127.0.0.1:8000"));

/** Deployed Next.js frontend (only used when frontend checks are enabled). */
export const FRONTEND_URL = trimTrailingSlash(env("FRONTEND_URL", "https://gims-dole12.vercel.app"));

/** smoke | small | medium | target */
export const PROFILE_NAME = env("PROFILE", "smoke");

/** Identifier used for result file names. */
export const RUN_ID = env("RUN_ID", defaultRunId());

/** Include the deployed frontend shell in the read workflow (off by default). */
export const ENABLE_FRONTEND_CHECKS = env("ENABLE_FRONTEND_CHECKS", "0") === "1";

/** Think time between iterations, seconds. */
export const THINK_TIME_MIN = Number(env("THINK_TIME_MIN", "3"));
export const THINK_TIME_MAX = Number(env("THINK_TIME_MAX", "5"));

/** Search term used by the beneficiary-search step. */
export const SEARCH_TERM = env("SEARCH_TERM", "a");

/** Dashboard reporting year. */
export const DASHBOARD_YEAR = env("DASHBOARD_YEAR", String(new Date().getFullYear()));

/** Thresholds in milliseconds. */
export const THRESHOLD_P95_MS = Number(env("THRESHOLD_P95_MS", "1500"));
export const THRESHOLD_P99_MS = Number(env("THRESHOLD_P99_MS", "3000"));

/** Absolute, forward-slash path for k6 `open()` (Windows friendly). */
export function accountsFilePath(rawPath) {
  return String(rawPath).replace(/\\/g, "/");
}
