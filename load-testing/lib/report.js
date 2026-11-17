import {
  API_BASE_URL,
  ENABLE_FRONTEND_CHECKS,
  FRONTEND_URL,
  PROFILE_NAME,
  RUN_ID,
  THRESHOLD_P95_MS,
  THRESHOLD_P99_MS,
} from "./config.js";
import { ENDPOINT_LABELS, ENDPOINTS } from "./endpoints.js";

function metric(data, name) {
  return (data.metrics && data.metrics[name]) || null;
}

function value(data, name, key) {
  const metricData = metric(data, name);
  if (!metricData || !metricData.values) {
    return undefined;
  }
  return metricData.values[key];
}

/** Counters that are only emitted when at least one event happened. */
function counterValue(data, name) {
  const count = value(data, name, "count");
  return count === undefined || count === null ? 0 : count;
}

function ms(valueMs) {
  if (valueMs === undefined || valueMs === null) {
    return "n/a";
  }
  return `${Math.round(valueMs)} ms`;
}

function percent(rate) {
  if (rate === undefined || rate === null) {
    return "n/a";
  }
  return `${(rate * 100).toFixed(2)}%`;
}

function number(valueNumber) {
  if (valueNumber === undefined || valueNumber === null) {
    return "n/a";
  }
  return String(Math.round(valueNumber));
}

function endpointRow(data, endpoint) {
  const requestCount = counterValue(data, `gims_requests_${endpoint}`);
  const failureCount = counterValue(data, `gims_failures_${endpoint}`);

  if (requestCount === 0) {
    return null;
  }

  return [
    ENDPOINT_LABELS[endpoint] || endpoint,
    number(requestCount),
    number(failureCount),
    ms(value(data, `gims_duration_${endpoint}`, "avg")),
    ms(value(data, `gims_duration_${endpoint}`, "p(95)")),
  ].join(" | ");
}

function renderMarkdown(data) {
  const failedRate = value(data, "http_req_failed", "rate");
  const durationAvg = value(data, "http_req_duration", "avg");
  const durationP95 = value(data, "http_req_duration", "p(95)");
  const durationP99 = value(data, "http_req_duration", "p(99)");
  const requests = value(data, "http_reqs", "count");
  const rps = value(data, "http_reqs", "rate");
  const iterations = value(data, "iterations", "count");
  const checksRate = value(data, "checks", "rate");
  const authFailures = counterValue(data, "gims_auth_failures");
  const authFailureRate = value(data, "gims_auth_failure_rate", "rate");
  const sessionRenewals = counterValue(data, "gims_session_renewals");

  const lines = [
    `# GIMS load-test report - ${RUN_ID}`,
    "",
    "## Run",
    "",
    `- Profile: **${PROFILE_NAME}**`,
    `- Backend under test: \`${API_BASE_URL}\``,
    `- Frontend checks: ${ENABLE_FRONTEND_CHECKS ? `enabled (\`${FRONTEND_URL}\`)` : "disabled (backend-only load)"}`,
    `- Thresholds: p(95) < ${THRESHOLD_P95_MS} ms, p(99) < ${THRESHOLD_P99_MS} ms, failed requests < 2 %, checks > 98 %, auth failures < 1 %`,
    "",
    "## Headline",
    "",
    "| Metric | Value |",
    "| --- | --- |",
    `| HTTP requests | ${number(requests)} |`,
    `| Throughput | ${rps === undefined ? "n/a" : `${rps.toFixed(2)} req/s`} |`,
    `| Iterations | ${number(iterations)} |`,
    `| Failed requests | ${percent(failedRate)} |`,
    `| Response time avg | ${ms(durationAvg)} |`,
    `| Response time p(95) | ${ms(durationP95)} |`,
    `| Response time p(99) | ${ms(durationP99)} |`,
    `| Check pass rate | ${percent(checksRate)} |`,
    `| Auth failures | ${number(authFailures)} (${percent(authFailureRate)}) |`,
    `| Session renewals | ${number(sessionRenewals)} |`,
    "",
    "## Per endpoint",
    "",
    "| Endpoint | Requests | Failures | Avg | p(95) |",
    "| --- | --- | --- | --- | --- |",
  ];

  const rows = ENDPOINTS.map((endpoint) => endpointRow(data, endpoint)).filter(Boolean);
  lines.push(...(rows.length > 0 ? rows : ["(no endpoint metrics were recorded)"]));

  const failedEndpoints = ENDPOINTS.map((endpoint) => {
    return { endpoint: endpoint, count: counterValue(data, `gims_failures_${endpoint}`) };
  }).filter((entry) => entry.count > 0);

  lines.push("", "## Failed requests", "");

  if (failedEndpoints.length === 0) {
    lines.push("No request failures were recorded.", "");
  } else {
    lines.push("| Endpoint | Failed requests |", "| --- | --- |");
    for (const entry of failedEndpoints) {
      lines.push(`| ${ENDPOINT_LABELS[entry.endpoint] || entry.endpoint} | ${number(entry.count)} |`);
    }
    lines.push("");
  }

  lines.push(
    "## Notes",
    "",
    "- Failures at 4xx/5xx are counted per endpoint; authentication rejections are tracked separately in `gims_auth_failures`.",
    "- Compare runs only with the same profile, data set and backend configuration (see README \"Interpreting results\").",
    "- Raw k6 summary data is written next to this report as JSON.",
    "",
  );

  return lines.join("\n");
}

function renderConsole(data) {
  const failedRate = value(data, "http_req_failed", "rate");
  const durationP95 = value(data, "http_req_duration", "p(95)");
  const durationP99 = value(data, "http_req_duration", "p(99)");
  const requests = value(data, "http_reqs", "count");
  const rps = value(data, "http_reqs", "rate");

  const lines = [
    "",
    `GIMS load test - profile ${PROFILE_NAME} (${RUN_ID})`,
    `  backend       : ${API_BASE_URL}`,
    `  requests      : ${number(requests)}`,
    `  throughput    : ${rps === undefined ? "n/a" : `${rps.toFixed(2)} req/s`}`,
    `  failed        : ${percent(failedRate)}`,
    `  p(95)/p(99)   : ${ms(durationP95)} / ${ms(durationP99)}`,
    `  report        : results/${RUN_ID}-report.md`,
    "",
  ];

  const failedEndpoints = ENDPOINTS.map((endpoint) => {
    return { endpoint: endpoint, count: counterValue(data, `gims_failures_${endpoint}`) };
  }).filter((entry) => entry.count > 0);

  if (failedEndpoints.length > 0) {
    lines.push("Failed requests by endpoint:");
    for (const entry of failedEndpoints) {
      lines.push(`  ${ENDPOINT_LABELS[entry.endpoint] || entry.endpoint}: ${number(entry.count)}`);
    }
    lines.push("");
  }

  return lines.join("\n");
}

export function handleSummary(data) {
  return {
    [`results/${RUN_ID}-report.md`]: renderMarkdown(data),
    [`results/${RUN_ID}-summary.json`]: JSON.stringify(
      {
        run_id: RUN_ID,
        profile: PROFILE_NAME,
        api_base_url: API_BASE_URL,
        frontend_url: ENABLE_FRONTEND_CHECKS ? FRONTEND_URL : null,
        metrics: data.metrics,
      },
      null,
      2,
    ),
    stdout: renderConsole(data),
  };
}
