import { check } from "k6";
import http from "k6/http";

import { API_BASE_URL } from "./config.js";
import { authFailureRate, authFailures, duration, failures, requests } from "./metrics.js";

/**
 * GIMS uses Laravel Sanctum in SPA mode: a first-party session cookie plus a
 * CSRF cookie. Browsers send `X-XSRF-TOKEN` with the raw (URL-decoded) value of
 * the `XSRF-TOKEN` cookie on mutating requests; k6 must do the same.
 *
 * The `Origin`/`Referer` headers are required so Sanctum's
 * EnsureFrontendRequestsAreStateful treats the request as coming from a
 * stateful domain (127.0.0.1:8000 / localhost:8000 are listed in
 * SANCTUM_STATEFUL_DOMAINS).
 */
export const BASE_HEADERS = {
  Origin: API_BASE_URL,
  Referer: `${API_BASE_URL}/`,
  Accept: "application/json",
};

function decodeCookieValue(value) {
  try {
    return decodeURIComponent(value);
  } catch (_error) {
    return value;
  }
}

/** Reads the newest value of a cookie from this VU's own cookie jar. */
export function cookieValue(name) {
  const cookies = http.cookieJar().cookiesForURL(API_BASE_URL);
  const values = cookies[name];
  if (!values || values.length === 0) {
    return null;
  }
  return decodeCookieValue(values[values.length - 1]);
}

/** Fetches `/sanctum/csrf-cookie` so the session + XSRF-TOKEN cookies exist. */
export function primeCsrf() {
  const response = http.get(`${API_BASE_URL}/sanctum/csrf-cookie`, {
    headers: BASE_HEADERS,
    tags: { endpoint: "csrf" },
  });

  return response.status >= 200 && response.status < 300;
}

/**
 * Logs one virtual user in with its own account. Returns the user payload on
 * success and `null` on authentication failure (counted in custom metrics).
 */
export function login(account) {
  primeCsrf();

  const csrfToken = cookieValue("XSRF-TOKEN");
  const headers = Object.assign({}, BASE_HEADERS, {
    "Content-Type": "application/json",
  });
  if (csrfToken) {
    headers["X-XSRF-TOKEN"] = csrfToken;
  }

  const response = http.post(
    `${API_BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ username: account.username, password: account.password }),
    { headers: headers, tags: { endpoint: "login" } },
  );

  duration.login.add(response.timings.duration);
  requests.login.add(1);

  const ok = check(response, {
    "login: HTTP 200": (res) => res.status === 200,
    "login: response carries a user": (res) => {
      try {
        return Boolean(res.json("user.username"));
      } catch (_error) {
        return false;
      }
    },
    "login: not forced to rotate password": (res) => {
      try {
        return res.json("user.must_change_password") !== true;
      } catch (_error) {
        return false;
      }
    },
  });

  if (!ok) {
    failures.login.add(1, { status: String(response.status) });
    authFailures.add(1);
    authFailureRate.add(true);
    return null;
  }

  authFailureRate.add(false);
  return response.json("user");
}

/** True when the current cookie jar still maps to an authenticated session. */
export function verifySession() {
  const response = http.get(`${API_BASE_URL}/api/v1/auth/user`, {
    headers: BASE_HEADERS,
    tags: { endpoint: "auth_user" },
  });

  duration.auth_user.add(response.timings.duration);
  requests.auth_user.add(1);

  const ok = check(response, {
    "auth/user: HTTP 200": (res) => res.status === 200,
  });

  if (!ok) {
    failures.auth_user.add(1, { status: String(response.status) });
  }

  return ok;
}

/** Drops this VU's cookies so the next login starts from a clean session. */
export function clearSession() {
  http.cookieJar().clear(API_BASE_URL);
}
