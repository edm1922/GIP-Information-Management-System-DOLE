import { Counter, Rate, Trend } from "k6/metrics";

import { ENDPOINTS } from "./endpoints.js";

/**
 * Per-endpoint metrics so the report can show response times, request counts
 * and failures for each GIMS operation instead of one blended number.
 */
export const duration = {};
export const requests = {};
export const failures = {};

for (const endpoint of ENDPOINTS) {
  duration[endpoint] = new Trend(`gims_duration_${endpoint}`);
  requests[endpoint] = new Counter(`gims_requests_${endpoint}`);
  failures[endpoint] = new Counter(`gims_failures_${endpoint}`);
}

/** Authentication-specific signals. */
export const authFailures = new Counter("gims_auth_failures");
export const authFailureRate = new Rate("gims_auth_failure_rate");
export const sessionRenewals = new Counter("gims_session_renewals");
