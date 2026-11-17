/**
 * Laravel API client for the GIMS SPA.
 *
 * Auth is Sanctum session-cookie based: every request sends credentials, and
 * mutating requests carry the XSRF-TOKEN cookie value in `X-XSRF-TOKEN`.
 * No tokens are stored in localStorage.
 */

import { filenameFromDisposition } from "@/lib/files";

/**
 * Origin the API lives on. Empty means "this origin", which is the deployed
 * mode: `next.config.ts` rewrites `/api/v1/*` and `/sanctum/csrf-cookie` to
 * `API_PROXY_TARGET` so the SPA and API stay same-site and the Sanctum session
 * cookie remains first-party (cross-site cookies are not sent, and
 * `document.cookie` cannot read another domain's `XSRF-TOKEN`).
 *
 * Local development instead sets `NEXT_PUBLIC_API_URL=http://localhost:8000`
 * in `.env.local`; use `localhost` (not 127.0.0.1) so the two are same-site.
 */
const rawApiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "").trim();

/**
 * Placeholder values people type into a hosting dashboard while switching a
 * variable off ("unset", "none", …). Treat them as absent: a literal
 * `unset/api/v1/...` request silently 404s against the app's own origin.
 * Remove the variable instead of setting it to one of these.
 */
const PLACEHOLDER_ENV_VALUES = new Set(["", "unset", "undefined", "null", "none", "false"]);

export const API_URL = (
  PLACEHOLDER_ENV_VALUES.has(rawApiUrl.toLowerCase()) ? "" : rawApiUrl
).replace(/\/+$/, "");

export class ApiError extends Error {
  status: number;
  data: unknown;
  code?: string;

  constructor(status: number, data: unknown) {
    const record = (data ?? {}) as Record<string, unknown>;
    super(typeof record.message === "string" ? record.message : `Request failed with status ${status}`);
    this.name = "ApiError";
    this.status = status;
    this.data = data;
    this.code = typeof record.code === "string" ? record.code : undefined;
  }

  /** Field-level validation errors from a 422 response. */
  get errors(): Record<string, string[]> {
    const record = (this.data ?? {}) as Record<string, unknown>;
    return (record.errors as Record<string, string[]>) ?? {};
  }
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((entry) => entry.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfRequest: Promise<void> | null = null;

async function refreshCsrf(): Promise<void> {
  csrfRequest ??= fetch(`${API_URL}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  })
    .then(() => undefined)
    .finally(() => {
      csrfRequest = null;
    });

  return csrfRequest;
}

type FetchOptions = Omit<RequestInit, "body"> & { body?: unknown };

/**
 * Laravel serializes an Eloquent paginator as `{ data, links, meta }`, but the
 * shared <Pagination> component (and every page) reads the flat legacy shape
 * (`current_page`, `last_page`, `total`, ...). Flatten nested paginators here so
 * call sites never have to unwrap `meta` by hand.
 */
function isPaginator(value: unknown): value is { data: unknown[]; links?: unknown; meta: Record<string, unknown> } {
  if (!value || typeof value !== "object" || Array.isArray(value)) return false;
  const record = value as Record<string, unknown>;
  const meta = record.meta as Record<string, unknown> | undefined;
  return (
    Array.isArray(record.data) &&
    !!meta &&
    typeof meta === "object" &&
    "current_page" in meta &&
    "last_page" in meta
  );
}

function normalizePaginators(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(normalizePaginators);
  if (!value || typeof value !== "object") return value;

  if (isPaginator(value)) {
    return { ...value.meta, data: value.data, links: value.meta.links ?? value.links };
  }

  const record = value as Record<string, unknown>;
  for (const key of Object.keys(record)) {
    record[key] = normalizePaginators(record[key]);
  }
  return record;
}

export async function apiFetch<T>(path: string, options: FetchOptions = {}, retry = true): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const mutating = !["GET", "HEAD", "OPTIONS"].includes(method);
  const isFormData = typeof FormData !== "undefined" && options.body instanceof FormData;

  if (mutating && !readCookie("XSRF-TOKEN")) {
    await refreshCsrf();
  }

  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (!isFormData && options.body !== undefined) {
    headers.set("Content-Type", "application/json");
  }

  const token = readCookie("XSRF-TOKEN");
  if (token && mutating) {
    headers.set("X-XSRF-TOKEN", token);
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    method,
    credentials: "include",
    headers,
    body:
      options.body === undefined || isFormData
        ? (options.body as BodyInit | undefined)
        : JSON.stringify(options.body),
  });

  if (response.status === 419 && mutating && retry) {
    await refreshCsrf();
    return apiFetch<T>(path, options, false);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get("content-type") ?? "";
  const data = contentType.includes("application/json") ? await response.json() : await response.text();

  if (!response.ok) {
    throw new ApiError(response.status, data);
  }

  return normalizePaginators(data) as T;
}

/** Bootstrap the CSRF cookie before the first mutating request (e.g. login). */
export function primeCsrf(): Promise<void> {
  return refreshCsrf();
}

/**
 * URL of an authorized document endpoint. Built client-side from the document
 * id instead of the API's `preview_url`, because that URL is generated from the
 * backend APP_URL and can point at the wrong port/host for the SPA.
 */
export function documentEndpoint(id: number, action: "preview" | "download"): string {
  return `${API_URL}/api/v1/intern-documents/${id}/${action}`;
}

/**
 * Fetch a private document as a Blob so it is authorized by the session cookie.
 * Loading it directly in an <iframe>/<img> would rely on the cookie being sent
 * for a subresource, which SameSite=Lax does not do across origins.
 */
export async function fetchDocumentBlob(id: number, action: "preview" | "download"): Promise<Blob> {
  const response = await fetch(documentEndpoint(id, action), {
    method: "GET",
    credentials: "include",
    headers: { Accept: "*/*" },
  });

  if (!response.ok) {
    const contentType = response.headers.get("content-type") ?? "";
    const data = contentType.includes("application/json") ? await response.json() : await response.text();
    throw new ApiError(response.status, data);
  }

  return response.blob();
}

/**
 * Download a session-protected file (CSV exports) and keep the server's
 * Content-Disposition filename.
 */
export async function fetchFile(path: string, fallbackName = "export.csv"): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(`${API_URL}${path}`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "text/csv,application/json" },
  });

  if (!response.ok) {
    const contentType = response.headers.get("content-type") ?? "";
    const data = contentType.includes("application/json") ? await response.json() : await response.text();
    throw new ApiError(response.status, data);
  }

  return {
    blob: await response.blob(),
    filename: filenameFromDisposition(response.headers.get("content-disposition"), fallbackName),
  };
}

export const api = {
  get: <T>(path: string) => apiFetch<T>(path),
  post: <T>(path: string, body?: unknown) => apiFetch<T>(path, { method: "POST", body }),
  put: <T>(path: string, body?: unknown) => apiFetch<T>(path, { method: "PUT", body }),
  patch: <T>(path: string, body?: unknown) => apiFetch<T>(path, { method: "PATCH", body }),
  delete: <T>(path: string) => apiFetch<T>(path, { method: "DELETE" }),
};
