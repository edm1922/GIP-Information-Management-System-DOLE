/**
 * Load profiles. Every profile uses the ramping-vus executor so the load ramps
 * up, holds, and ramps back down instead of stopping abruptly.
 *
 * Target profile follows the brief: 20 users over 30 s, 4 minutes at 20
 * concurrent users, then a 30 s ramp-down (3-5 min sustained window).
 */
import { ENDPOINTS } from "../lib/endpoints.js";
import { ENABLE_FRONTEND_CHECKS, THRESHOLD_P95_MS, THRESHOLD_P99_MS } from "../lib/config.js";

const STAGES = {
  smoke: [
    { duration: "5s", target: 1 },
    { duration: "20s", target: 1 },
    { duration: "5s", target: 0 },
  ],
  small: [
    { duration: "15s", target: 5 },
    { duration: "60s", target: 5 },
    { duration: "15s", target: 0 },
  ],
  medium: [
    { duration: "20s", target: 10 },
    { duration: "120s", target: 10 },
    { duration: "20s", target: 0 },
  ],
  target: [
    { duration: "30s", target: 20 },
    { duration: "240s", target: 20 },
    { duration: "30s", target: 0 },
  ],
};

/** Endpoints whose metrics are always emitted by the read workflow. */
const CORE_ENDPOINTS = ENDPOINTS.filter((endpoint) => !endpoint.startsWith("frontend_"));

function buildThresholds() {
  const thresholds = {
    checks: ["rate>0.98"],
    http_req_failed: ["rate<0.02"],
    http_req_duration: [`p(95)<${THRESHOLD_P95_MS}`, `p(99)<${THRESHOLD_P99_MS}`],
    gims_auth_failure_rate: ["rate<0.01"],
  };

  for (const endpoint of CORE_ENDPOINTS) {
    thresholds[`gims_duration_${endpoint}`] = [`p(95)<${THRESHOLD_P95_MS}`];
  }

  if (ENABLE_FRONTEND_CHECKS) {
    thresholds.gims_duration_frontend_login = [`p(95)<${THRESHOLD_P95_MS}`];
    thresholds.gims_duration_frontend_dashboard = [`p(95)<${THRESHOLD_P95_MS}`];
  }

  return thresholds;
}

export const PROFILES = STAGES;

export function getProfile(name) {
  const stages = STAGES[name];
  if (!stages) {
    throw new Error(`Unknown PROFILE "${name}". Use one of: ${Object.keys(STAGES).join(", ")}.`);
  }

  const maxVus = stages.reduce((highest, stage) => Math.max(highest, stage.target), 0);

  return {
    name: name,
    maxVus: maxVus,
    stages: stages,
    scenario: {
      executor: "ramping-vus",
      startVUs: 0,
      stages: stages,
      gracefulRampDown: "10s",
      gracefulStop: "15s",
      tags: { profile: name },
    },
    thresholds: buildThresholds(),
  };
}

/** k6 `summaryTrendStats` so the report includes p(99) as well as avg/p95. */
export const SUMMARY_TREND_STATS = ["avg", "min", "med", "p(90)", "p(95)", "p(99)", "max"];
