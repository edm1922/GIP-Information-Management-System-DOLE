import { check } from "k6";
import http from "k6/http";

import { BASE_HEADERS } from "./auth.js";
import { API_BASE_URL, DASHBOARD_YEAR, SEARCH_TERM } from "./config.js";
import { duration, failures, requests } from "./metrics.js";

/**
 * One GET against the GIMS API with per-endpoint metrics and a 2xx check.
 * The session cookie is attached automatically by k6's cookie jar.
 */
function get(endpoint, path) {
  const response = http.get(`${API_BASE_URL}${path}`, {
    headers: BASE_HEADERS,
    tags: { endpoint: endpoint },
  });

  duration[endpoint].add(response.timings.duration);
  requests[endpoint].add(1);
  requests[endpoint].add(1);

  const ok = check(response, {
    [`${endpoint}: HTTP 2xx`]: (res) => res.status >= 200 && res.status < 300,
  });

  if (!ok) {
    failures[endpoint].add(1, { status: String(response.status) });
  }

  return response;
}

/** Dashboard widgets (PESO managers get the intern statistics payload). */
export function getDashboard(year) {
  return get("dashboard", `/api/v1/dashboard?year=${encodeURIComponent(year || DASHBOARD_YEAR)}`);
}

/** Beneficiary (= intern) directory, first page. */
export function listBeneficiaries() {
  return get("interns_list", "/api/v1/interns?page=1");
}

/** Beneficiary search (`search` is the parameter the API expects). */
export function searchBeneficiaries(term) {
  return get("interns_search", `/api/v1/interns?search=${encodeURIComponent(term || SEARCH_TERM)}`);
}

/** Beneficiary details for one intern record. */
export function getBeneficiary(id) {
  return get("intern_details", `/api/v1/interns/${id}`);
}

/**
 * "Batch listing" step. GIMS has no dedicated batch endpoint: `batch` (and
 * `beneficiary`) are columns on the intern directory rows, and the closest
 * batch-oriented read is the active-beneficiary list. Documented in the README.
 */
export function listBatches() {
  return get("batch_listing", "/api/v1/interns?status=active&page=1");
}

/** Extracts a beneficiary id from a list/search response, if present. */
export function extractBeneficiaryId(response) {
  try {
    const id = response.json("interns.data.0.id");
    return id === undefined || id === null ? null : id;
  } catch (_error) {
    return null;
  }
}
